<?php

namespace App\Telegram;

final class TelegramAudience
{
    public function allows(string $telegramUserId): bool
    {
        if (! preg_match('/^[1-9][0-9]{0,18}$/D', $telegramUserId)) {
            return false;
        }
        $setting = config('telegram.allowed_user_ids', '');
        if (! is_string($setting)) {
            return false;
        }
        if (trim($setting) === '') {
            // A blank list retains invitation by a valid individual key.
            return true;
        }
        $ids = array_map('trim', explode(',', $setting));
        foreach ($ids as $id) {
            if (! preg_match('/^[1-9][0-9]{0,18}$/D', $id)) {
                // Never discard malformed entries and accidentally broaden access.
                return false;
            }
        }

        return in_array($telegramUserId, $ids, true);
    }
}
