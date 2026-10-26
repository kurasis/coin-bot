<?php

namespace App\Wallets;

use App\Access\AccessDenied;
use App\Access\AccessService;
use App\Blockchain\Contracts\ChainClient;
use App\Domain\UserScope;
use App\Monitoring\BlockScanner;
use App\Operations\MaintenanceGuard;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class BackfillService
{
    public function __construct(
        private readonly AccessService $access,
        private readonly UserScope $scope,
        private readonly BlockScanner $scanner,
    ) {}

    public function create(int $userId, int $userWalletId, int $from, ?int $to = null, bool $notify = false): object
    {
        if ($from < 0 || ($to !== null && $to < $from)) {
            throw new InvalidArgumentException('Backfill needs a nonnegative ordered block range.');
        }

        return DB::transaction(function () use ($userId, $userWalletId, $from, $to, $notify): object {
            $this->access->requireGrant($userId);
            $wallet = $this->scope->wallet($userId, $userWalletId);
            if (! $wallet->enabled) {
                throw new InvalidArgumentException('Enable the wallet before creating a historical import.');
            }
            $boundary = DB::table('scan_cursors')->where('chain', $wallet->chain)->where('role', 'primary')->min('last_block');
            $end = $to ?? (int) ($boundary ?? -1);
            if ($boundary === null || $end < $from || $end > (int) $boundary) {
                throw new InvalidArgumentException('Backfill must end at or before the primary completed boundary.');
            }
            $existing = DB::table('backfill_jobs')->where('user_wallet_id', $userWalletId)
                ->where('from_block', $from)->where('to_block', $end)->where('notify', $notify)->first();
            if ($existing !== null) {
                return $existing;
            }
            $watchSetId = DB::table('watch_sets')->insertGetId([
                'chain' => $wallet->chain,
                'version' => ((int) DB::table('watch_sets')->where('chain', $wallet->chain)->max('version')) + 1,
                'cutover_block' => $from - 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('watch_set_wallets')->insert(['watch_set_id' => $watchSetId, 'wallet_id' => $wallet->wallet_id]);
            $jobId = DB::table('backfill_jobs')->insertGetId([
                'user_id' => $userId, 'user_wallet_id' => $userWalletId, 'from_block' => $from, 'to_block' => $end,
                'notify' => $notify, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $cursorId = $this->scanner->initialize($wallet->chain, $from, $watchSetId, 'backfill', 'backfill:'.$jobId);
            DB::table('backfill_jobs')->where('id', $jobId)->update(['cursor_id' => $cursorId]);
            if ($from < (int) $wallet->start_block) {
                DB::table('user_wallets')->where('id', $userWalletId)->where('user_id', $userId)
                    ->update(['start_block' => $from, 'updated_at' => now()]);
            }
            DB::table('audit_events')->insert([
                'actor_user_id' => $userId, 'action' => 'backfill.created', 'subject_type' => 'backfill_job',
                'subject_id' => (string) $jobId, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return DB::table('backfill_jobs')->where('id', $jobId)->first();
        }, 3);
    }

    public function run(int $userId, int $jobId, ChainClient $client, int $maxRange = 100): object
    {
        app(MaintenanceGuard::class)->assertRunning();
        $job = DB::table('backfill_jobs')->where('id', $jobId)->where('user_id', $userId)->first();
        if ($job === null) {
            throw new InvalidArgumentException('Backfill job not found.');
        }
        if ($job->status === 'completed') {
            return $job;
        }
        try {
            $this->access->requireGrant($userId);
            $wallet = $this->scope->wallet($userId, (int) $job->user_wallet_id);
            if (! $wallet->enabled || $wallet->chain !== $client->chain()) {
                throw new InvalidArgumentException('The backfill wallet is disabled or the network does not match.');
            }
            DB::table('backfill_jobs')->where('id', $jobId)->update(['status' => 'running', 'last_error' => null, 'updated_at' => now()]);
            $this->scanner->scanCursor($client, (int) $job->cursor_id, $maxRange, (bool) $job->notify, (int) $job->user_wallet_id, (int) $job->to_block, (bool) $job->notify);
            $lastBlock = (int) DB::table('scan_cursors')->where('id', $job->cursor_id)->value('last_block');
            DB::table('backfill_jobs')->where('id', $jobId)->update([
                'status' => $lastBlock >= (int) $job->to_block ? 'completed' : 'pending',
                'available_at' => now()->addSecond(), 'last_error' => null, 'updated_at' => now(),
            ]);
        } catch (Throwable $error) {
            DB::table('backfill_jobs')->where('id', $jobId)->update([
                'status' => $error instanceof AccessDenied ? 'paused' : 'failed',
                'available_at' => now()->addSeconds(30), 'last_error' => 'Historical processing is blocked; check access, provider health, and range coverage.',
                'updated_at' => now(),
            ]);
            throw $error;
        }

        return DB::table('backfill_jobs')->where('id', $jobId)->first();
    }
}
