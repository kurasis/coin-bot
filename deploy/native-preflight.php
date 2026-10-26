<?php

use App\Operations\MaintenanceGuard;
use App\Telegram\TelegramAudience;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

try {
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $mode = $argv[1] ?? '';
    $issues = [];
    if (! in_array($mode, ['enroll', 'start'], true)) {
        $issues[] = 'Use enroll or start.';
    }
    if (config('database.default') !== 'sqlite'
        || config('database.connections.sqlite.database') !== '/var/lib/coin-bot/database.sqlite'
        || config('database.connections.sqlite.url') !== null) {
        $issues[] = 'Keep the native SQLite database at /var/lib/coin-bot/database.sqlite with DB_URL unset.';
    }
    if (config('operations.backup_directory') !== '/mnt/coin-bot-backups') {
        $issues[] = 'Set OPERATIONS_BACKUP_DIRECTORY=/mnt/coin-bot-backups.';
    }
    if (! is_string(config('app.key')) || config('app.key') === '') {
        $issues[] = 'The installation application key is missing; preserve the original key.';
    }
    $token = config('telegram.token');
    if (! is_string($token) || ! preg_match('/^[A-Za-z0-9:_.-]{1,512}$/D', $token)) {
        $issues[] = 'Set TELEGRAM_BOT_TOKEN in /home/www/coinbot/.env.';
    }
    $ids = config('telegram.allowed_user_ids');
    if (! is_string($ids) || (trim($ids) !== ''
        && ! app(TelegramAudience::class)->allows(trim(explode(',', $ids)[0])))) {
        $issues[] = 'TELEGRAM_ALLOWED_USER_IDS must be blank or a valid comma-separated numeric ID list.';
    }
    if (app(MaintenanceGuard::class)->isPaused()) {
        $issues[] = 'Operations are paused. Complete recovery checks before explicitly resuming.';
    }
    if ($mode === 'start') {
        foreach (['ethereum', 'tron'] as $chain) {
            foreach (['primary', 'secondary'] as $role) {
                foreach ($chain === 'tron' ? ['rpc_url', 'native_url'] : ['rpc_url'] as $field) {
                    $value = config("blockchain.chains.$chain.providers.$role.$field");
                    if (! is_string($value) || trim($value) === '') {
                        $issues[] = 'Configure '.strtoupper($chain.'_'.$role.'_'.$field).'.';
                    }
                }
            }
            if (! DB::table('scan_cursors')->where('chain', $chain)->where('role', 'primary')->exists()) {
                $issues[] = 'Add an authorized '.$chain.' wallet before starting all workers.';
            }
        }
    }
    if ($issues !== []) {
        fwrite(STDERR, implode(PHP_EOL, $issues).PHP_EOL);
        exit(1);
    }
    echo 'Native startup configuration checked; live provider acceptance is separate.'.PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, 'Native startup check failed. Check the private configuration and database; secrets are omitted.'.PHP_EOL);
    exit(1);
}
