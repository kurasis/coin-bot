<?php

namespace Tests\Feature;

use App\Access\AccessService;
use App\Monitoring\BlockScanner;
use App\Operations\Heartbeat;
use App\Operations\MaintenanceGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MonitoringCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config([
            'operations.state_directory' => sys_get_temp_dir().'/coin-bot-command-test-'.bin2hex(random_bytes(8)),
            'operations.alert_user_id' => 1,
            'operations.alert_failure_threshold' => 3,
            'operations.alert_min_outage_seconds' => 30,
            'operations.alert_check_interval_seconds' => 15,
            'telegram.token' => 'synthetic-command-token',
        ]);
        foreach (['primary', 'secondary'] as $role) {
            config([
                'blockchain.chains.ethereum.providers.'.$role.'.rpc_url' => 'https://'.$role.'-monitoring.invalid/private-rpc-key',
                'blockchain.chains.ethereum.providers.'.$role.'.attempts' => 1,
                'blockchain.chains.ethereum.providers.'.$role.'.max_backoff_ms' => 0,
            ]);
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(config('operations.state_directory'));
        parent::tearDown();
    }

    public function test_disabled_early_worker_is_neutral_and_records_its_idle_heartbeat(): void
    {
        config(['ethereum.early_enabled' => false]);
        $this->artisan('ethereum:early-scan --once')->assertSuccessful();
        $this->assertNotNull(app(Heartbeat::class)->lastSeen('scan-ethereum-early'));
        $this->assertDatabaseCount('monitoring_incidents', 0);
        $this->assertDatabaseCount('ethereum_early_state', 0);
        Http::assertNothingSent();
    }

    public function test_intentional_pause_and_missing_setup_do_not_raise_api_alerts(): void
    {
        config(['ethereum.early_enabled' => true]);
        $this->artisan('ethereum:early-scan --once')->assertFailed();
        $this->artisan('chain:scan ethereum --once')->assertFailed();
        $this->initialize();
        app(MaintenanceGuard::class)->pause('Synthetic intentional pause.');
        foreach (range(1, 3) as $ignored) {
            $this->artisan('ethereum:early-scan --once')->assertFailed();
            $this->artisan('chain:scan ethereum --once')->assertFailed();
            $this->travel(15)->seconds();
        }
        $this->assertDatabaseCount('monitoring_incidents', 0);
        $this->assertDatabaseCount('monitoring_alerts', 0);
        Http::assertNothingSent();
    }

    public function test_real_cli_outage_cycles_respect_retry_after_and_deliver_one_private_operator_alert(): void
    {
        $this->initialize();
        $access = app(AccessService::class);
        $user = $access->activate($access->issue()->token, '740000001')->userId;
        $destination = DB::table('chat_destinations')->insertGetId([
            'chat_id' => '740000001', 'kind' => 'private', 'enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('user_chat_destinations')->insert([
            'user_id' => $user, 'destination_id' => $destination, 'enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(1, $user);
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/sendMessage')) {
                return Http::response(['ok' => true, 'result' => ['message_id' => 1, 'chat' => ['id' => $request['chat_id']]]]);
            }

            return Http::response(['error' => 'Synthetic private upstream data'], 429, ['Retry-After' => '300']);
        });
        foreach ([1, 2, 3] as $count) {
            $this->artisan('chain:scan ethereum --once')->assertFailed();
            $this->assertDatabaseHas('monitoring_incidents', ['chain' => 'ethereum', 'consecutive_failures' => $count]);
            $this->assertDatabaseCount('monitoring_alerts', $count === 3 ? 1 : 0);
            if ($count < 3) {
                $this->travel(15)->seconds();
            }
        }
        Http::assertSentCount(2); // Both providers only once; cooldowns do not send more requests.
        $this->artisan('telegram:deliver --once')->assertSuccessful();
        Http::assertSentCount(3);
        $this->assertDatabaseHas('monitoring_alerts', ['user_id' => 1, 'chat_id' => '740000001', 'status' => 'sent', 'kind' => 'outage']);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sendMessage')
            && (string) $request['chat_id'] === '740000001'
            && str_contains($request['text'], 'Ethereum monitoring')
            && ! str_contains($request['text'], 'private-rpc-key')
            && ! str_contains($request['text'], 'private upstream'));
    }

    public function test_a_permission_failure_with_working_fallback_does_not_open_a_cli_incident(): void
    {
        $cursor = $this->initialize();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'primary-monitoring')) {
                return Http::response('', 403);
            }

            return $this->rpc($request);
        });
        $this->artisan('chain:scan ethereum --once')->assertSuccessful();
        $this->assertDatabaseHas('scan_cursors', ['id' => $cursor, 'last_block' => 9]);
        $this->assertDatabaseCount('monitoring_incidents', 0);
        $this->assertDatabaseCount('monitoring_alerts', 0);
        $this->assertNotNull(app(Heartbeat::class)->lastSeen('scan-ethereum'));
    }

    public function test_early_cli_initializes_its_own_boundary_without_touching_finalized_coverage(): void
    {
        $cursor = $this->initialize();
        config(['ethereum.early_enabled' => true]);
        Http::fake(fn (Request $request) => $this->rpc($request));
        $this->artisan('ethereum:early-scan --once')->assertSuccessful();
        $this->assertDatabaseHas('ethereum_early_state', ['id' => 1, 'last_block' => 9, 'start_block' => 10]);
        $this->assertDatabaseHas('scan_cursors', ['id' => $cursor, 'last_block' => 9]);
        $this->assertDatabaseCount('transfers', 0);
        $this->assertDatabaseCount('notification_outbox', 0);
        $this->assertDatabaseCount('monitoring_alerts', 0);
        $this->assertNotNull(app(Heartbeat::class)->lastSeen('scan-ethereum-early'));
    }

    #[DataProvider('logLimitStatuses')]
    public function test_http_log_limits_split_all_blocks_and_addresses_before_advancing_coverage(int $status): void
    {
        $cursor = $this->initialize();
        $set = DB::table('scan_cursors')->find($cursor)->watch_set_id;
        foreach ([1, 2] as $number) {
            $wallet = DB::table('wallets')->insertGetId(['chain' => 'ethereum', 'address' => '0x'.str_pad((string) $number, 40, '0', STR_PAD_LEFT)]);
            DB::table('watch_set_wallets')->insert(['watch_set_id' => $set, 'wallet_id' => $wallet]);
        }
        $completed = [];
        $queries = [];
        Http::fake(function (Request $request) use ($status, &$completed, &$queries) {
            if ($request['method'] !== 'eth_getLogs') {
                if ($request['method'] === 'eth_getBlockByNumber') {
                    $tag = $request['params'][0];
                    $number = $tag === 'finalized' ? 13 : hexdec(substr($tag, 2));

                    return Http::response(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => [
                        'number' => '0x'.dechex($number), 'hash' => '0x'.hash('sha256', 'header-'.$number), 'timestamp' => '0x6553f100',
                    ]]);
                }

                return $this->rpc($request);
            }
            $filter = $request['params'][0];
            $topics = $filter['topics'];
            $addresses = $topics[count($topics) - 1];
            $queries[] = [count($addresses), $filter['fromBlock'], $filter['toBlock']];
            if ($filter['fromBlock'] !== $filter['toBlock'] || count($addresses) > 1) {
                return Http::response(['error' => ['code' => -32602, 'message' => 'SECRET query block range limit; too many topics']], $status);
            }
            $completed[] = [$filter['fromBlock'], count($topics) === 2 ? 'outgoing' : 'incoming', $addresses[0]];

            return Http::response(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => []]);
        });

        $this->assertSame(0, Artisan::call('chain:scan', ['chain' => 'ethereum', '--once' => true]));
        $this->assertDatabaseHas('scan_cursors', ['id' => $cursor, 'last_block' => 13]);
        $this->assertDatabaseHas('scan_ranges', ['cursor_id' => $cursor, 'from_block' => 10, 'to_block' => 13, 'status' => 'completed']);
        if ($status === 413) {
            $this->assertSame([1, '0xa', '0xd'], $queries[1]); // Shrink the payload before multiplying block requests.
        }
        $this->assertCount(16, $completed); // Four blocks, two addresses, both directions.
        $this->assertCount(16, array_unique(array_map('serialize', $completed)));
        $this->assertDatabaseHas('provider_health', ['chain' => 'ethereum', 'provider' => 'primary', 'failure_count' => 0, 'opens_until' => null]);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'secondary-monitoring'));
        $this->assertStringNotContainsString('SECRET', Artisan::output());
    }

    public static function logLimitStatuses(): array
    {
        return [[400], [413]];
    }

    public function test_rejected_requests_report_roles_and_operations_without_leaking_errors(): void
    {
        $this->initialize();
        Http::fake(fn () => Http::response(['error' => ['message' => 'SECRET invalid parameter https://upstream.invalid/key']], 400));

        $this->assertSame(1, Artisan::call('chain:scan', ['chain' => 'ethereum', '--once' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString('"primary":{"operation":"network","reason":"request_rejected","http_status":400', $output);
        $this->assertStringContainsString('"secondary":{"operation":"network","reason":"request_rejected","http_status":400', $output);
        $this->assertStringNotContainsString('SECRET', $output);
        $this->assertStringNotContainsString('private-rpc-key', $output);
        $this->assertStringNotContainsString('upstream.invalid', $output);
        $this->assertDatabaseHas('scan_cursors', ['last_block' => 9]);
        $this->assertDatabaseCount('scan_ranges', 0);
        Http::assertSentCount(2);
    }

    private function initialize(): int
    {
        $watch = DB::table('watch_sets')->insertGetId(['chain' => 'ethereum', 'version' => 1, 'cutover_block' => 9, 'activated_at' => now()]);

        return app(BlockScanner::class)->initialize('ethereum', 10, $watch);
    }

    private function rpc(Request $request): mixed
    {
        $result = '0x1';
        if ($request['method'] === 'eth_getBlockByNumber') {
            $tag = $request['params'][0];
            $number = $tag === 'latest' ? 15 : ($tag === 'finalized' ? 9 : hexdec(substr($tag, 2)));
            $result = ['number' => '0x'.dechex($number), 'hash' => '0x'.hash('sha256', 'header-'.$number), 'timestamp' => '0x'.dechex(now()->timestamp)];
        }

        return Http::response(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => $result]);
    }
}
