<?php

namespace App\Console\Commands;

use App\Wallets\WalletInput;
use App\Wallets\WalletService;
use Illuminate\Console\Command;
use Throwable;

class WalletUpdateCommand extends Command
{
    protected $signature = 'wallet:update {wallet : User wallet ID} {--user=} {--label=} {--direction=} {--min-amount=} {--enable}';

    protected $description = 'Update one user-owned wallet without changing another user\'s settings';

    public function handle(WalletService $wallets): int
    {
        try {
            $settings = [];
            foreach (['label' => 'label', 'direction' => 'direction', 'min-amount' => 'min_amount'] as $option => $field) {
                if ($this->option($option) !== null) {
                    $settings[$field] = $this->option($option);
                }
            }
            if ($this->option('enable')) {
                $settings['enabled'] = true;
            }
            $wallets->update(WalletInput::integer($this->option('user'), true), WalletInput::integer($this->argument('wallet'), true), $settings);
            $this->info('Wallet settings updated.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Wallet update failed. Check the selected user, wallet, settings, and access.');

            return self::FAILURE;
        }
    }
}
