<?php

namespace App\Console\Commands;

use App\Wallets\WalletInput;
use App\Wallets\WalletService;
use Illuminate\Console\Command;
use Throwable;

class WalletAddCommand extends Command
{
    protected $signature = 'wallet:add {chain} {address} {--user= : Internal user ID} {--label=} {--start-block=} {--direction=both} {--min-amount=0 : Minimum raw USDT units (six decimals)}';

    protected $description = 'Add a wallet for one explicitly selected user (operator console only)';

    public function handle(WalletService $wallets): int
    {
        try {
            $wallet = $wallets->add(WalletInput::integer($this->option('user'), true), $this->argument('chain'), $this->argument('address'),
                $this->option('label') ?? '', $this->option('start-block') === null ? null : WalletInput::integer($this->option('start-block')),
                $this->option('direction'), $this->option('min-amount'));
            $this->info('User wallet '.$wallet->id.' is registered from block '.$wallet->start_block.'.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Wallet registration failed. Check user access, parameters, and provider configuration.');

            return self::FAILURE;
        }
    }
}
