<?php

namespace App\Console\Commands;

use App\Access\AccessCommand;
use App\Access\AccessService;
use Illuminate\Support\Facades\DB;

class AccessUsersCommand extends AccessCommand
{
    protected $signature = 'access:users {--telegram-id= : Filter by an immutable numeric Telegram user ID}';

    protected $description = 'List operator-only user IDs and access state for scoped wallet administration';

    public function handle(AccessService $access): int
    {
        $telegramId = $this->option('telegram-id');
        if ($telegramId !== null && ! preg_match('/^[1-9][0-9]{0,18}$/D', (string) $telegramId)) {
            $this->error('Provide a valid numeric Telegram user ID.');

            return self::INVALID;
        }
        $users = DB::table('users')->when($telegramId !== null, fn ($query) => $query->where('telegram_user_id', (string) $telegramId))->orderBy('id')->get();
        $this->table(['User ID', 'Telegram user ID', 'Account state', 'Access'], $users->map(fn ($user): array => [
            $user->id, $user->telegram_user_id, $user->state,
            $access->activeGrant((int) $user->id) === null ? 'inactive' : 'active',
        ])->all());

        return self::SUCCESS;
    }
}
