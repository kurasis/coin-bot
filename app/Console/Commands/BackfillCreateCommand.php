<?php

namespace App\Console\Commands;

use App\Wallets\BackfillService;
use App\Wallets\WalletInput;
use Illuminate\Console\Command;
use Throwable;

class BackfillCreateCommand extends Command
{
    protected $signature = 'wallet:backfill-create {wallet : User wallet ID} {from : First historical block} {--user=} {--to=} {--notify : Explicitly authorize historical notifications to this user only}';

    protected $description = 'Create a resumable historical import; notification delivery is disabled by default';

    public function handle(BackfillService $backfills): int
    {
        try {
            $job = $backfills->create(WalletInput::integer($this->option('user'), true), WalletInput::integer($this->argument('wallet'), true),
                WalletInput::integer($this->argument('from')), $this->option('to') === null ? null : WalletInput::integer($this->option('to')), (bool) $this->option('notify'));
            $this->info('Historical import '.$job->id.' is '.$job->status.'.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Historical import creation failed. Check ownership, access, and the completed primary boundary.');

            return self::FAILURE;
        }
    }
}
