<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TelegramConfigurationTest extends TestCase
{
    #[DataProvider('audienceSettings')]
    public function test_bootstrapped_environment_preserves_invitation_mode_and_fails_closed_for_invalid_settings(string $setting, array $expected): void
    {
        $program = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $audience = $app->make(App\Telegram\TelegramAudience::class);
        echo json_encode([$audience->allows('1001'), $audience->allows('3003')], JSON_THROW_ON_ERROR);
        PHP;
        $process = new Process([PHP_BINARY, '-r', $program], base_path(), [
            'TELEGRAM_ALLOWED_USER_IDS' => $setting,
        ]);
        $process->setTimeout(10)->mustRun();

        // These are recipient eligibility checks; blank still requires key-based access.
        $this->assertSame($expected, json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
    }

    public static function audienceSettings(): array
    {
        return [
            'blank keeps individual-key invitations' => ['', [true, true]],
            'whitespace keeps individual-key invitations' => ['  ', [true, true]],
            'exact list restricts recipient eligibility' => ['1001,2002', [true, false]],
            'malformed list denies all recipients' => ['1001,,2002', [false, false]],
            'reserved true is not a recipient list' => ['true', [false, false]],
            'reserved false is not invitation mode' => ['false', [false, false]],
            'reserved null is not invitation mode' => ['null', [false, false]],
        ];
    }
}
