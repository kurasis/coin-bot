<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Access\AccessService;
use App\Blockchain\Contracts\ChainClient;
use App\Blockchain\Exceptions\RpcException;
use App\Monitoring\BlockScanner;
use App\Monitoring\CheckAttempt;
use App\Monitoring\CheckedChainClient;
use App\Monitoring\EarlyWitnessException;
use App\Monitoring\MonitoringCycle;
use App\Monitoring\ScanException;
use App\Reconciliation\ProviderCircuitBreaker;
use App\Reconciliation\ProviderCoolingDown;
use App\Reconciliation\ResilientEarlyScanner;
use App\Reconciliation\ResilientScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MonitoringFailureCyclesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'operations.alert_user_id' => 1,
            'operations.alert_failure_threshold' => 3,
            'operations.alert_min_outage_seconds' => 30,
            'operations.alert_check_interval_seconds' => 15,
            'blockchain.chains.ethereum.providers.primary.history_start_block' => 0,
            'blockchain.chains.ethereum.providers.secondary.history_start_block' => 0,
        ]);
    }

    #[DataProvider('apiFailures')]
    public function test_every_provider_api_failure_can_use_independent_validated_fallback(bool $retryable, ?int $status): void
    {
        [$primary, $secondary, $cursor] = $this->providers();
        $primary->networkError = new RpcException('Private upstream error must not be retained.', $retryable, $status);
        $result = $this->cycle($primary, $secondary);
        $this->assertSame($cursor, $result->cursorId);
        $this->assertSame(1, $primary->verifyCalls);
        $this->assertSame(1, $secondary->verifyCalls);
        $this->assertDatabaseHas('scan_cursors', ['id' => $cursor, 'last_block' => 10]);
        $this->assertDatabaseHas('provider_health', ['provider' => 'primary', 'failure_count' => 1]);
        $this->assertDatabaseCount('monitoring_alerts', 0);
        $this->assertDatabaseCount('monitoring_incidents', 0);
    }

    public static function apiFailures(): array
    {
        return [
            'forbidden' => [false, 403],
            'authentication' => [false, 401],
            'malformed-or-wrong-network' => [false, null],
            'rate-limited' => [true, 429],
            'server-error' => [true, 503],
            'connection' => [true, null],
        ];
    }

    public function test_immediate_circuit_cooldowns_do_not_inflate_failed_monitoring_attempts(): void
    {
        [$primary, $secondary] = $this->providers();
        $this->recipient();
        $primary->networkError = $secondary->networkError = new RpcException('Quota exhausted.', true, 429);
        $this->failedCycle($primary, $secondary);
        $this->assertSame(1, DB::table('monitoring_incidents')->sole()->consecutive_failures);
        for ($index = 0; $index < 2; $index++) {
            try {
                $this->cycle($primary, $secondary);
                $this->fail('Expected cooling-down providers.');
            } catch (ProviderCoolingDown) {
                $this->assertSame(1, DB::table('monitoring_incidents')->sole()->consecutive_failures);
            }
        }
        $this->assertSame(1, $primary->verifyCalls);
        $this->assertSame(1, $secondary->verifyCalls);
        $this->assertDatabaseCount('monitoring_alerts', 0);

        $this->travel(16)->seconds();
        $this->failedCycle($primary, $secondary);
        $this->assertSame(2, DB::table('monitoring_incidents')->sole()->consecutive_failures);
        $this->assertDatabaseCount('monitoring_alerts', 0);
        $this->travel(31)->seconds();
        $this->failedCycle($primary, $secondary);
        $this->assertSame(3, DB::table('monitoring_incidents')->sole()->consecutive_failures);
        $this->assertDatabaseCount('monitoring_alerts', 1);
        $this->assertDatabaseHas('monitoring_alerts', ['kind' => 'outage', 'user_id' => 1, 'status' => 'pending']);
        $this->assertStringNotContainsString('Quota exhausted', DB::table('monitoring_alerts')->sole()->text);
    }

    public function test_checked_idle_success_resets_streak_and_cancels_an_unsent_outage(): void
    {
        [$primary, $secondary, $cursor] = $this->providers();
        $this->recipient();
        $primary->networkError = $secondary->networkError = new RpcException('Temporarily unavailable.', true, 503);
        for ($index = 0; $index < 3; $index++) {
            $this->failedCycle($primary, $secondary);
            $this->travel(5)->minutes();
        }
        $this->assertDatabaseCount('monitoring_alerts', 1);
        $primary->networkError = null;
        $primary->head = 9;
        $result = $this->cycle($primary, $secondary);
        $this->assertTrue($result->idle);
        $this->assertDatabaseHas('scan_cursors', ['id' => $cursor, 'last_block' => 9]);
        $this->assertDatabaseHas('monitoring_incidents', ['chain' => 'ethereum', 'state' => 'healthy', 'consecutive_failures' => 0]);
        $this->assertDatabaseHas('monitoring_alerts', ['kind' => 'outage', 'status' => 'cancelled']);
        $this->assertDatabaseCount('monitoring_alerts', 1);
    }

    public function test_long_mandatory_cooldown_is_sampled_without_more_http_or_fast_restart_inflation(): void
    {
        [$primary, $secondary] = $this->providers();
        $this->recipient();
        $primary->networkError = $secondary->networkError = new RpcException('Mandatory quota wait.', true, 429, 300);
        $this->failedCycle($primary, $secondary);
        for ($index = 0; $index < 2; $index++) {
            try {
                $this->cycle($primary, $secondary);
                $this->fail('Expected the mandatory cooldown.');
            } catch (ProviderCoolingDown) {
                $this->assertDatabaseHas('monitoring_incidents', ['chain' => 'ethereum', 'consecutive_failures' => 1]);
            }
        }
        $this->assertDatabaseCount('monitoring_alerts', 0);
        foreach ([2, 3] as $count) {
            $this->travel(15)->seconds();
            try {
                $this->cycle($primary, $secondary);
                $this->fail('Expected the mandatory cooldown.');
            } catch (ProviderCoolingDown) {
                $this->assertDatabaseHas('monitoring_incidents', ['chain' => 'ethereum', 'consecutive_failures' => $count]);
            }
        }
        $this->assertSame(1, $primary->verifyCalls);
        $this->assertSame(1, $secondary->verifyCalls);
        $this->assertDatabaseHas('monitoring_alerts', ['kind' => 'outage', 'status' => 'pending']);
        $this->assertDatabaseCount('monitoring_alerts', 1);
    }

    public function test_missing_scan_initialization_performs_no_provider_operation_or_incident_write(): void
    {
        $attempt = new CheckAttempt;
        $primary = new FailureCycleClient('primary');
        $secondary = new FailureCycleClient('secondary');
        try {
            app(MonitoringCycle::class)->run('ethereum', fn () => app(ResilientScanner::class)->scan(
                new CheckedChainClient($primary, $attempt), new CheckedChainClient($secondary, $attempt),
            ), $attempt);
            $this->fail('Expected explicit initialization guard.');
        } catch (ScanException) {
            $this->assertFalse($attempt->attempted);
            $this->assertSame(0, $attempt->calls);
            $this->assertSame(0, $primary->verifyCalls);
            $this->assertSame(0, $secondary->verifyCalls);
            $this->assertDatabaseCount('monitoring_incidents', 0);
        }
    }

    public function test_early_monitoring_uses_secondary_on_nonretryable_api_failure_without_advancing_finalized_cursor(): void
    {
        config(['ethereum.early_enabled' => true]);
        [$primary, $secondary, $cursor] = $this->providers();
        $primary->networkError = new RpcException('Primary authentication rejected.', false, 401);
        $attempt = new CheckAttempt;
        $result = app(MonitoringCycle::class)->run('ethereum', fn () => app(ResilientEarlyScanner::class)->scan(
            new CheckedChainClient($primary, $attempt), new CheckedChainClient($secondary, $attempt),
        ), $attempt);
        $this->assertTrue($result->idle);
        $this->assertTrue($attempt->attempted);
        $this->assertSame(1, $primary->verifyCalls);
        $this->assertSame(1, $secondary->verifyCalls);
        $this->assertDatabaseHas('ethereum_early_state', ['id' => 1, 'last_block' => 10]);
        $this->assertDatabaseHas('scan_cursors', ['id' => $cursor, 'last_block' => 9]);
        $this->assertDatabaseHas('provider_health', ['provider' => 'primary', 'last_http_status' => 401, 'failure_count' => 1]);
        $this->assertDatabaseCount('monitoring_incidents', 0);
    }

    public function test_reorg_witness_does_not_bypass_an_independent_providers_mandatory_cooldown(): void
    {
        config(['ethereum.early_enabled' => true]);
        [$primary, $secondary] = $this->providers();
        app(ProviderCircuitBreaker::class)->failed($secondary, new RpcException('Mandatory limit.', true, 429, 300));
        $attempt = new CheckAttempt;
        $first = new CheckedChainClient($primary, $attempt);
        $backup = new CheckedChainClient($secondary, $attempt);
        app(ResilientEarlyScanner::class)->scan($first, $backup);
        $this->assertSame(0, $secondary->verifyCalls);
        $primary->hashOverride = '0x'.str_repeat('f', 64);
        try {
            app(ResilientEarlyScanner::class)->scan($first, $backup);
            $this->fail('A reorg cannot proceed without independently available evidence.');
        } catch (ScanException) {
            $this->assertSame(0, $secondary->verifyCalls);
            $this->assertDatabaseHas('ethereum_early_state', ['id' => 1, 'last_block' => 10, 'reorg_pending' => true]);
            $this->assertDatabaseCount('ethereum_early_events', 0);
            $this->assertDatabaseCount('notification_outbox', 0);
        }
    }

    public function test_failed_reorg_witness_cools_only_the_witness_and_preserves_the_healthy_active_provider(): void
    {
        config(['ethereum.early_enabled' => true]);
        [$primary, $secondary] = $this->providers();
        $attempt = new CheckAttempt;
        $first = new CheckedChainClient($primary, $attempt);
        $backup = new CheckedChainClient($secondary, $attempt);
        app(ResilientEarlyScanner::class)->scan($first, $backup);
        $primary->hashOverride = '0x'.str_repeat('f', 64);
        $secondary->networkError = new RpcException('Private witness failure must not be retained.', true, 429, 300);
        try {
            app(ResilientEarlyScanner::class)->scan($first, $backup);
            $this->fail('Independent witness failure must defer the reorganization.');
        } catch (EarlyWitnessException $error) {
            $this->assertSame('secondary', $error->provider);
            $this->assertSame(429, $error->httpStatus);
            $this->assertSame(300, $error->retryAfterSeconds);
            $this->assertNull($error->getPrevious());
            $this->assertStringNotContainsString('Private witness', $error->getMessage());
        }
        $this->assertDatabaseHas('provider_health', ['provider' => 'primary', 'failure_count' => 0, 'opens_until' => null]);
        $this->assertDatabaseHas('provider_health', ['provider' => 'secondary', 'failure_count' => 1, 'last_http_status' => 429]);
        $this->assertSame(1, $secondary->verifyCalls);
        try {
            app(ResilientEarlyScanner::class)->scan($first, $backup);
            $this->fail('Evidence is still required while the witness cools down.');
        } catch (ScanException) {
            $this->assertSame(1, $secondary->verifyCalls);
        }
        $this->assertDatabaseCount('notification_outbox', 0);
    }

    public function test_disabled_early_mode_does_not_turn_existing_cooldowns_into_new_outage_samples(): void
    {
        config(['ethereum.early_enabled' => false]);
        [$primary, $secondary] = $this->providers();
        foreach ([$primary, $secondary] as $client) {
            app(ProviderCircuitBreaker::class)->failed($client, new RpcException('Mandatory wait.', true, 429, 300));
        }
        $attempt = new CheckAttempt;
        $result = app(MonitoringCycle::class)->run('ethereum', fn () => app(ResilientEarlyScanner::class)->scan(
            new CheckedChainClient($primary, $attempt), new CheckedChainClient($secondary, $attempt),
        ), $attempt);
        $this->assertTrue($result->idle);
        $this->assertFalse($attempt->attempted);
        $this->assertSame(0, $primary->verifyCalls);
        $this->assertSame(0, $secondary->verifyCalls);
        $this->assertDatabaseCount('monitoring_incidents', 0);
    }

    public function test_a_cycle_without_provider_calls_does_not_clear_a_real_failure_streak(): void
    {
        [$primary, $secondary] = $this->providers();
        $primary->networkError = $secondary->networkError = new RpcException('Unavailable.', true);
        $this->failedCycle($primary, $secondary);
        $result = app(MonitoringCycle::class)->run('ethereum', fn () => 'intentionally skipped', new CheckAttempt);
        $this->assertSame('intentionally skipped', $result);
        $this->assertDatabaseHas('monitoring_incidents', ['chain' => 'ethereum', 'consecutive_failures' => 1]);
    }

    public function test_tracking_keeps_metadata_free_and_tracks_each_actual_provider_operation(): void
    {
        $attempt = new CheckAttempt;
        $wrapped = new CheckedChainClient(new FailureCycleClient('primary'), $attempt);
        $this->assertSame('ethereum', $wrapped->chain());
        $this->assertSame('primary', $wrapped->provider());
        $this->assertFalse($attempt->attempted);
        $wrapped->verifyNetwork();
        $wrapped->finalizedBlock();
        $wrapped->latestBlock();
        $wrapped->block(10);
        $wrapped->logs(10, 10, [], 'incoming');
        $wrapped->receipt('0x'.str_repeat('a', 64));
        $wrapped->solidifiedBlockReceipts(10);
        $this->assertTrue($attempt->attempted);
        $this->assertSame(7, $attempt->calls);
    }

    public function test_original_chain_error_survives_incident_storage_failure_and_completed_work_stays_successful(): void
    {
        [$primary, $secondary] = $this->providers();
        Log::spy();
        DB::unprepared("CREATE TRIGGER reject_incident BEFORE INSERT ON monitoring_incidents BEGIN SELECT RAISE(ABORT, 'Synthetic incident failure'); END");
        $failure = new RpcException('Original sanitized failure.', true, 503);
        $attempt = new CheckAttempt;
        $attempt->mark();
        try {
            app(MonitoringCycle::class)->run('ethereum', fn () => throw $failure, $attempt);
            $this->fail('Expected original chain error.');
        } catch (RpcException $error) {
            $this->assertSame($failure, $error);
        }
        DB::unprepared('DROP TRIGGER reject_incident');
        DB::table('monitoring_incidents')->insert([
            'chain' => 'ethereum', 'consecutive_failures' => 1,
            'first_failure_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::unprepared("CREATE TRIGGER reject_incident_update BEFORE UPDATE ON monitoring_incidents BEGIN SELECT RAISE(ABORT, 'Synthetic incident update failure'); END");
        $primary->networkError = new RpcException('Primary unavailable.', false, 403);
        $this->assertSame(1, $this->cycle($primary, $secondary)->cursorId);
        $this->assertDatabaseHas('monitoring_incidents', ['chain' => 'ethereum', 'consecutive_failures' => 1]);
        Log::shouldHaveReceived('warning')->twice()->with('Monitoring incident status could not be persisted.');
    }

    private function providers(): array
    {
        $set = DB::table('watch_sets')->insertGetId(['chain' => 'ethereum', 'version' => 1, 'cutover_block' => 9, 'activated_at' => now()]);
        $cursor = app(BlockScanner::class)->initialize('ethereum', 10, $set);

        return [new FailureCycleClient('primary'), new FailureCycleClient('secondary'), $cursor];
    }

    private function recipient(): void
    {
        $key = app(AccessService::class)->issue();
        $user = app(AccessService::class)->activate($key->token, '1001')->userId;
        $this->assertSame(1, $user);
        $destination = DB::table('chat_destinations')->insertGetId(['chat_id' => '1001', 'kind' => 'private', 'enabled' => true]);
        DB::table('user_chat_destinations')->insert(['user_id' => $user, 'destination_id' => $destination, 'enabled' => true]);
    }

    private function cycle(FailureCycleClient $primary, FailureCycleClient $secondary): mixed
    {
        $attempt = new CheckAttempt;

        return app(MonitoringCycle::class)->run('ethereum', fn () => app(ResilientScanner::class)->scan(
            new CheckedChainClient($primary, $attempt), new CheckedChainClient($secondary, $attempt),
        ), $attempt);
    }

    private function failedCycle(FailureCycleClient $primary, FailureCycleClient $secondary): void
    {
        try {
            $this->cycle($primary, $secondary);
            $this->fail('Expected the attempted monitoring cycle to fail.');
        } catch (RpcException) {
            // The real cycle runner has already persisted the attempted failure.
        }
    }
}

class FailureCycleClient implements ChainClient
{
    public ?RpcException $networkError = null;

    public int $verifyCalls = 0;

    public int $head = 10;

    public ?string $hashOverride = null;

    public function __construct(private readonly string $role) {}

    public function chain(): string
    {
        return 'ethereum';
    }

    public function provider(): string
    {
        return $this->role;
    }

    public function verifyNetwork(): void
    {
        $this->verifyCalls++;
        if ($this->networkError !== null) {
            throw $this->networkError;
        }
    }

    public function finalizedBlock(): array
    {
        return $this->block($this->head);
    }

    public function latestBlock(): array
    {
        return $this->block($this->head + 6);
    }

    public function block(int $number): array
    {
        return ['number' => $number, 'hash' => $this->hashOverride ?? '0x'.str_pad(dechex($number), 64, '0', STR_PAD_LEFT), 'timestamp' => 1793030400];
    }

    public function logs(int $from, int $to, array $normalizedWatchedAddresses, string $direction): array
    {
        return [];
    }

    public function receipt(string $transactionHash): array
    {
        return ['transactionHash' => $transactionHash, 'blockHash' => $this->block(10)['hash'], 'blockNumber' => '0xa', 'status' => '0x1', 'logs' => []];
    }

    public function solidifiedBlockReceipts(int $number): array
    {
        return [];
    }
}
