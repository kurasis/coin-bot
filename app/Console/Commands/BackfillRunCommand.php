<?php

namespace App\Console\Commands;

use App\Blockchain\ChainClientFactory;
use App\Operations\Heartbeat;
use App\Operations\MaintenanceGuard;
use App\Wallets\BackfillService;
use App\Wallets\WalletInput;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class BackfillRunCommand extends Command
{
    protected $signature = 'wallet:backfill-run {--job=} {--once} {--max-range=100} {--sleep=5}';

    protected $description = 'Process bounded historical imports without rewinding live chain cursors';

    public function handle(BackfillService $backfills, ChainClientFactory $clients): int
    {
        try {
            $maxRange = WalletInput::integer($this->option('max-range'), true);
            $sleep = min(60, max(1, WalletInput::integer($this->option('sleep'), true)));
            $jobId = $this->option('job') === null ? null : WalletInput::integer($this->option('job'), true);
        } catch (Throwable) {
            $this->error('Worker arguments are invalid.');

            return self::FAILURE;
        }
        do {
            try {
                app(MaintenanceGuard::class)->assertRunning();
                $query = DB::table('backfill_jobs')->orderBy('id');
                if ($jobId !== null) {
                    $query->where('id', $jobId);
                } else {
                    $query->whereIn('status', ['pending', 'running', 'failed'])
                        ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()));
                }
                $job = $query->first();
                if ($jobId !== null && $job === null) {
                    $this->error('Historical import not found.');

                    return self::FAILURE;
                }
                if ($job !== null) {
                    $chain = DB::table('user_wallets')->join('wallets', 'wallets.id', '=', 'user_wallets.wallet_id')
                        ->where('user_wallets.id', $job->user_wallet_id)->value('wallets.chain');
                    $backfills->run((int) $job->user_id, (int) $job->id, $clients->make($chain), $maxRange);
                }
                app(Heartbeat::class)->record('backfill');
            } catch (Throwable) {
                $this->error('Historical worker paused this unit; inspect access, provider, and maintenance state.');
                if ($this->option('once')) {
                    return self::FAILURE;
                }
            }
            if (! $this->option('once')) {
                sleep($sleep);
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }
}
