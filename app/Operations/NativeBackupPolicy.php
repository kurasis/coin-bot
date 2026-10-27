<?php

namespace App\Operations;

use RuntimeException;

final class NativeBackupPolicy
{
    public const LOCAL_DIRECTORY = '/var/lib/coin-bot/backups';

    public const OFFHOST_DIRECTORY = '/mnt/coin-bot-backups';

    public const LOCAL_DROP_IN = '/etc/systemd/system/coin-bot@.service.d/local-backups.conf';

    public function mode(): string
    {
        $mode = config('operations.backup_mode', 'offhost');
        if (! in_array($mode, ['offhost', 'local'], true)) {
            throw new RuntimeException('Choose the native backup mode with sudo coin-bot backup:local or backup:offhost.');
        }

        return $mode;
    }

    public function validate(): void
    {
        $local = $this->mode() === 'local';
        $expected = $local ? self::LOCAL_DIRECTORY : self::OFFHOST_DIRECTORY;
        if (config('operations.backup_directory') !== $expected || config('operations.require_backup') !== true) {
            throw new RuntimeException('Use sudo coin-bot backup:local or backup:offhost to configure the backup directory and keep backup checks enabled.');
        }
        if ($local) {
            $template = base_path('deploy/systemd/coin-bot-local-backups.conf');
            if (! is_file(self::LOCAL_DROP_IN) || is_link(self::LOCAL_DROP_IN)
                || fileowner(self::LOCAL_DROP_IN) !== 0 || (fileperms(self::LOCAL_DROP_IN) & 0022) !== 0
                || ! is_file($template) || ! hash_equals(hash_file('sha256', $template), hash_file('sha256', self::LOCAL_DROP_IN))) {
                throw new RuntimeException('Local backup mode needs its managed systemd settings. Run sudo coin-bot backup:local.');
            }
        } elseif (file_exists(self::LOCAL_DROP_IN) || is_link(self::LOCAL_DROP_IN)) {
            throw new RuntimeException('Off-host mode conflicts with local systemd settings. Run sudo coin-bot backup:offhost.');
        }
        if (is_link($expected) || ! is_dir($expected) || ! is_writable($expected)) {
            throw new RuntimeException('The selected backup directory must be a writable regular directory for coin-bot.');
        }
        if ($local && (fileperms($expected) & 0077) !== 0) {
            throw new RuntimeException('Keep local backup directory permissions private: sudo coin-bot backup:local.');
        }
    }
}
