<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class BlockchainConfigurationTest extends TestCase
{
    #[DataProvider('historySettings')]
    public function test_bootstrapped_provider_configuration_distinguishes_unknown_history_from_genesis(string $setting, ?string $expected): void
    {
        $program = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $values = [];
        foreach (['ethereum', 'tron'] as $chain) {
            foreach (['primary', 'secondary'] as $provider) {
                $values[] = config("blockchain.chains.$chain.providers.$provider.history_start_block");
            }
        }
        echo json_encode($values, JSON_THROW_ON_ERROR);
        PHP;
        $process = new Process([PHP_BINARY, '-r', $program], base_path(), [
            'ETHEREUM_PRIMARY_HISTORY_START_BLOCK' => $setting,
            'ETHEREUM_SECONDARY_HISTORY_START_BLOCK' => $setting,
            'TRON_PRIMARY_HISTORY_START_BLOCK' => $setting,
            'TRON_SECONDARY_HISTORY_START_BLOCK' => $setting,
        ]);
        $process->mustRun();

        $this->assertSame(array_fill(0, 4, $expected), json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
    }

    public static function historySettings(): array
    {
        return [
            'blank environment template' => ['', null],
            'whitespace is still unknown' => ['  ', null],
            'verified genesis is not unknown' => ['0', '0'],
            'verified retained history' => ['12345', '12345'],
        ];
    }
}
