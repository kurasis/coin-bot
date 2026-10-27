<?php

namespace App\Console\Commands;

use App\Operations\Heartbeat;
use App\Telegram\MonitoringAlertWorker;
use App\Telegram\OutboxWorker;
use App\Telegram\ReplyWorker;
use Illuminate\Console\Command;

class TelegramDeliver extends Command
{
    protected $signature = 'telegram:deliver {--once : Attempt at most one monitoring alert, reply and transfer delivery}';

    protected $description = 'Deliver leased Telegram replies and authorized transfer notifications.';

    public function handle(OutboxWorker $outbox, ReplyWorker $replies, MonitoringAlertWorker $alerts): int
    {
        $replyFirst = false;
        do {
            $alerts->runOnce();
            if ($replyFirst) {
                $replies->runOnce();
                $outbox->runOnce();
            } else {
                $outbox->runOnce();
                $replies->runOnce();
            }
            $replyFirst = ! $replyFirst;
            if (class_exists(Heartbeat::class)) {
                app(Heartbeat::class)->record('telegram-deliver');
            }
            if (! $this->option('once')) {
                usleep(100000);
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }
}
