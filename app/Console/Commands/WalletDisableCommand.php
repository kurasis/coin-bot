<?php

namespace App\Console\Commands;

use App\Wallets\WalletInput;
use App\Wallets\WalletService;
use Illuminate\Console\Command;
use Throwable;

class WalletDisableCommand extends Command
{
    protected $signature = 'wallet:disable {wallet : User wallet ID} {--user=}';

    protected $description = 'Disable a wallet subscription for one user while preserving shared monitoring';

    public function handle(WalletService $wallets): int
    {
        try {
            $wallets->disable(WalletInput::integer($this->option('user'), true), WalletInput::integer($this->argument('wallet'), true));
            $this->info('The selected user wallet is disabled.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Wallet disable failed. Check the selected user, wallet, and access.');

            return self::FAILURE;
        }
    }
}
