<?php

namespace App\Console\Commands;

use App\Wallets\WalletInput;
use App\Wallets\WalletService;
use Illuminate\Console\Command;
use Throwable;

class WalletListCommand extends Command
{
    protected $signature = 'wallet:list {--user=}';

    protected $description = 'List wallets for one explicitly selected user';

    public function handle(WalletService $wallets): int
    {
        try {
            $records = $wallets->wallets(WalletInput::integer($this->option('user'), true));
            $this->table(['ID', 'Chain', 'Address', 'Private label', 'Enabled', 'Start block'], $records->map(fn ($wallet) => [
                $wallet->id, $wallet->chain, $wallet->address, $wallet->label, $wallet->enabled ? 'yes' : 'no', $wallet->start_block,
            ])->all());

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Wallet listing failed. Select an active user explicitly.');

            return self::FAILURE;
        }
    }
}
