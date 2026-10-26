<?php

namespace App\Console\Commands;

use App\Operations\Heartbeat;
use App\Telegram\TelegramException;
use App\Telegram\TelegramPoller;
use Illuminate\Console\Command;
use LogicException;

class TelegramPoll extends Command
{
    protected $signature = 'telegram:poll {--once : Process one long-poll request}';

    protected $description = 'Process private Telegram activation and account controls.';

    public function handle(TelegramPoller $poller): int
    {
        do {
            try {
                $poller->runOnce();
                if (class_exists(Heartbeat::class)) {
                    app(Heartbeat::class)->record('telegram-poll');
                }
            } catch (TelegramException|LogicException) {
                $this->error('Telegram polling failed. Check credentials and service health; secrets are omitted.');
                if ($this->option('once')) {
                    return self::FAILURE;
                }
                sleep(5);
            }
            if (! $this->option('once')) {
                usleep(200000);
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }
}
