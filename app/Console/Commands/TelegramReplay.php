<?php

namespace App\Console\Commands;

use App\Telegram\OutboxWorker;
use Illuminate\Console\Command;
use LogicException;

class TelegramReplay extends Command
{
    protected $signature = 'telegram:replay {id : Authorized failed outbox row ID}';

    protected $description = 'Explicitly retry a failed transfer notification after correcting its cause.';

    public function handle(OutboxWorker $worker): int
    {
        $id = $this->argument('id');
        if (! is_string($id) || ! preg_match('/^[1-9][0-9]*$/D', $id)) {
            $this->error('A positive outbox ID is required.');

            return self::FAILURE;
        }
        try {
            $worker->replay((int) $id);
        } catch (LogicException) {
            $this->error('Only an authorized failed delivery can be replayed.');

            return self::FAILURE;
        }
        $this->info('Delivery queued for retry.');

        return self::SUCCESS;
    }
}
