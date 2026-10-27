<?php

namespace Tests\Feature;

use App\Blockchain\ChainClientFactory;
use App\Monitoring\ScanCadence;
use App\Operations\ApiPolicy;
use App\Operations\ApiUsage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ApiUsageTest extends TestCase
{
    private string $state;

    protected function setUp(): void
    {
        parent::setUp();
        $this->state = sys_get_temp_dir().'/coin-bot-api-test-'.bin2hex(random_bytes(8));
        config(['operations.api_usage_enabled' => true, 'operations.state_directory' => $this->state]);
        foreach (['ethereum', 'tron'] as $chain) {
            foreach (['primary', 'secondary'] as $role) {
                config(['blockchain.chains.'.$chain.'.providers.'.$role => ['rpc_url' => 'https://rpc.test/PRIVATE-KEY', 'attempts' => 1]]);
            }
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->state);
        parent::tearDown();
    }

    public function test_pacing_is_weighted_and_shared_between_independent_instances_without_holding_a_lock_while_waiting(): void
    {
        $first = new VirtualApiUsage;
        $second = new VirtualApiUsage;
        $policy = new ApiPolicy('alchemy', 'alchemy', 200, 800000);
        $first->admit($policy, 'eth_getLogs');
        $second->onSleep = function (): void {
            // Another process can read the group during this wait.
            $this->assertSame(1, (new ApiUsage)->report()['providers']['ethereum_primary']['requests']);
        };
        config(['blockchain.chains.ethereum.providers.primary.usage_profile' => 'alchemy']);
        $second->admit($policy, 'eth_chainId');
        $this->assertSame(300000, $second->waited);
        $this->assertSame(0, $first->waited);
        $report = $second->report('2026-10-27')['providers']['ethereum_primary'];
        $this->assertSame(2, $report['requests']);
        $this->assertSame(60, $report['estimated_units']); // chainId is free for billing, but paced at 5 throughput CU.
        $this->assertSame(300, $report['wait_ms']);
        $this->assertSame(['eth_getLogs' => 1, 'eth_chainId' => 1], $report['methods']);
    }

    public function test_separate_accounts_do_not_block_each_other_and_day_rollover_retains_bounded_history(): void
    {
        $usage = new VirtualApiUsage;
        $alchemy = new ApiPolicy('alchemy', 'alchemy', 200, 800000);
        $quicknode = new ApiPolicy('quicknode', 'quicknode', 0, 250000);
        $usage->admit($alchemy, 'eth_getLogs');
        $usage->admit($quicknode, 'eth_getLogs');
        $this->assertSame(0, $usage->waited);
        for ($day = 1; $day <= 40; $day++) {
            $usage->clock += 86400 * 1000000;
            $usage->admit($quicknode, 'eth_getBlockByNumber');
        }
        $stored = json_decode(file_get_contents($this->state.'/api/quicknode.json'), true);
        $this->assertCount(35, $stored['days']);
        $this->assertArrayNotHasKey('2026-10-27', $stored['days']);
        $this->assertSame(0600, fileperms($this->state.'/api/quicknode.json') & 0777);
    }

    public function test_transport_counts_each_retry_and_omits_endpoint_headers_and_raw_errors(): void
    {
        config(['blockchain.chains.ethereum.providers.primary.usage_profile' => 'alchemy',
            'blockchain.chains.ethereum.providers.primary.rate_per_second' => 0,
            'blockchain.chains.ethereum.providers.primary.attempts' => 2,
            'blockchain.chains.ethereum.providers.primary.backoff_ms' => 0,
            'blockchain.chains.ethereum.providers.primary.headers' => ['Authorization' => 'SECRET-HEADER']]);
        Http::fakeSequence()->push('SECRET body', 503)->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x1']);
        app(ChainClientFactory::class)->make('ethereum')->verifyNetwork();
        $report = app(ApiUsage::class)->report()['providers']['ethereum_primary'];
        $this->assertSame(2, $report['requests']);
        $this->assertSame(0, $report['estimated_units']);
        $this->assertSame(1, $report['transport_failures']);
        $this->assertSame(0, Artisan::call('ops:api-usage'));
        $text = Artisan::output().file_get_contents($this->state.'/api/alchemy.json');
        foreach (['PRIVATE-KEY', 'SECRET', 'rpc.test', 'Authorization'] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
        Http::assertSentCount(2);
    }

    public function test_unreadable_or_corrupt_controls_block_http_without_resetting_state(): void
    {
        config(['blockchain.chains.ethereum.providers.primary.usage_profile' => 'alchemy']);
        File::ensureDirectoryExists($this->state.'/api', 0700);
        $path = $this->state.'/api/alchemy.json';
        file_put_contents($path, 'PRIVATE corrupt state');
        try {
            app(ChainClientFactory::class)->make('ethereum')->verifyNetwork();
            $this->fail('Expected a local control error.');
        } catch (RuntimeException $error) {
            $this->assertSame('Local API pacing and usage state is unavailable.', $error->getMessage());
        }
        $this->assertSame('PRIVATE corrupt state', file_get_contents($path));
        Http::assertNothingSent();
    }

    public function test_symlinked_data_is_never_followed(): void
    {
        File::ensureDirectoryExists($this->state.'/api', 0700);
        file_put_contents($this->state.'/sentinel', 'PRIVATE sentinel');
        symlink($this->state.'/sentinel', $this->state.'/api/alchemy.json');
        try {
            (new ApiUsage)->admit(new ApiPolicy('alchemy', 'alchemy', 200, 800000), 'eth_getLogs');
            $this->fail('Expected symlink refusal.');
        } catch (RuntimeException) {
            $this->assertSame('PRIVATE sentinel', file_get_contents($this->state.'/sentinel'));
        }
    }

    public function test_profiles_detect_known_vendors_and_validate_shared_group_names(): void
    {
        $alchemy = ApiPolicy::from(['rpc_url' => 'https://eth-mainnet.g.alchemy.com/v2/PRIVATE'], 'ethereum', 'primary');
        $infura = ApiPolicy::from(['rpc_url' => 'https://mainnet.infura.io/v3/PRIVATE'], 'ethereum', 'secondary');
        $this->assertSame('alchemy', $alchemy->group);
        $this->assertSame(200, $alchemy->rate);
        $this->assertSame(255, $infura->billedUnits('eth_getLogs'));
        $this->assertSame('infura', $infura->group);
        $this->expectException(\InvalidArgumentException::class);
        ApiPolicy::from(['rpc_url' => 'https://rpc.test', 'rate_group' => '../PRIVATE'], 'tron', 'primary');
    }

    public function test_empty_report_and_invalid_date_do_not_make_requests_or_create_ledger(): void
    {
        $this->assertSame(0, Artisan::call('ops:api-usage'));
        $this->assertDirectoryDoesNotExist($this->state);
        $this->assertSame(1, Artisan::call('ops:api-usage', ['--date' => '2026-02-31']));
        Http::assertNothingSent();
    }

    public function test_live_tron_cadence_counts_rpc_time_but_backlog_and_other_chains_keep_fast_progress(): void
    {
        config(['blockchain.chains.tron.scan_min_interval_seconds' => 15]);
        $this->assertSame(12000000, ScanCadence::remainingMicros('tron', false, false, 15, 3000000));
        $this->assertSame(500000, ScanCadence::remainingMicros('tron', false, true, 15, 500000));
        $this->assertSame(29000000, ScanCadence::remainingMicros('tron', true, false, 30, 1000000));
        $this->assertSame(1000000, ScanCadence::remainingMicros('ethereum', false, false, 15, 0));
        $this->assertSame(0, ScanCadence::remainingMicros('tron', false, false, 15, 20000000));
    }

    public function test_parallel_processes_share_accounting_and_pacing(): void
    {
        $code = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['operations.state_directory' => $argv[1]]);
$usage = new App\Operations\ApiUsage;
$policy = new App\Operations\ApiPolicy('parallel', 'requests', 50, 1000);
for ($i=0; $i<8; $i++) { $usage->admit($policy, 'eth_getLogs'); }
PHP;
        $started = microtime(true);
        $children = [];
        for ($i = 0; $i < 3; $i++) {
            $process = proc_open([PHP_BINARY, '-r', $code, $this->state], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            $this->assertIsResource($process);
            $children[] = [$process, $pipes];
        }
        foreach ($children as [$process, $pipes]) {
            stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $errors);
        }
        $this->assertGreaterThanOrEqual(0.42, microtime(true) - $started);
        $stored = json_decode(file_get_contents($this->state.'/api/parallel.json'), true);
        $this->assertSame(24, $stored['days'][gmdate('Y-m-d')]['requests']);
        $this->assertSame(24, $stored['days'][gmdate('Y-m-d')]['estimated_units']);
    }
}

class VirtualApiUsage extends ApiUsage
{
    public int $clock = 1793059200000000;

    public int $waited = 0;

    public ?\Closure $onSleep = null;

    protected function nowMicros(): int
    {
        return $this->clock;
    }

    protected function sleepMicros(int $microseconds): void
    {
        if ($this->onSleep !== null) {
            ($this->onSleep)();
        }
        $this->waited += $microseconds;
        $this->clock += $microseconds;
    }
}
