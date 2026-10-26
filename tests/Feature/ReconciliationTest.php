<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Blockchain\AddressCodec;
use App\Blockchain\Contracts\ChainClient;
use App\Blockchain\EthereumRpcClient;
use App\Blockchain\Exceptions\RangeLimitException;
use App\Blockchain\Exceptions\RpcException;
use App\Blockchain\RpcTransport;
use App\Blockchain\TronRpcClient;
use App\Monitoring\BlockScanner;
use App\Monitoring\ScanException;
use App\Reconciliation\ProviderCircuitBreaker;
use App\Reconciliation\ProviderCoolingDown;
use App\Reconciliation\Reconciler;
use App\Reconciliation\ResilientScanner;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_matching_independent_range_has_separate_coverage_and_no_duplicate_delivery(): void
    {
        [$primary, $secondary, $cursor] = $this->pair();
        (new BlockScanner)->scan($primary);
        $result = (new Reconciler)->reconcile($primary, $secondary);
        $this->assertSame(0, $result->repairedEvents);
        $this->assertDatabaseCount('transfers', 1);
        $this->assertDatabaseCount('notification_outbox', 1);
        $this->assertDatabaseHas('scan_cursors', ['role' => 'reconciliation', 'provider' => 'secondary', 'last_block' => 10]);
        $this->assertNotNull(DB::table('scan_ranges')->where('cursor_id', $cursor)->sole()->reconciled_at);
        $this->assertDatabaseHas('reconciliation_checks', ['status' => 'matched']);
        $this->assertTrue((new Reconciler)->reconcile($primary, $secondary)->idle);
    }

    public function test_primary_index_omission_is_recovered_only_after_two_receipt_witnesses_agree(): void
    {
        [$primary, $secondary] = $this->pair();
        $primary->omitLogs = true;
        (new BlockScanner)->scan($primary);
        $this->assertDatabaseCount('transfers', 0);
        $result = (new Reconciler)->reconcile($primary, $secondary);
        $this->assertSame(1, $result->repairedEvents);
        $this->assertGreaterThan(0, $primary->receiptCalls);
        $this->assertGreaterThan(0, $secondary->receiptCalls);
        $this->assertDatabaseCount('transfers', 1);
        $this->assertDatabaseCount('notification_outbox', 1);
        $this->assertDatabaseHas('outbox_authorizations', ['historical_override' => false]);
        $this->assertDatabaseHas('reconciliation_checks', ['status' => 'repaired', 'repaired_events' => 1]);
    }

    public function test_secondary_index_omission_is_distinguished_from_missing_execution(): void
    {
        [$primary, $secondary] = $this->pair();
        (new BlockScanner)->scan($primary);
        $secondary->omitLogs = true;
        $result = (new Reconciler)->reconcile($primary, $secondary);
        $this->assertSame(0, $result->repairedEvents);
        $this->assertSame(1, $result->indexOmissions);
        $this->assertDatabaseCount('transfers', 1);
        $this->assertDatabaseCount('notification_outbox', 1);
        $this->assertDatabaseHas('reconciliation_checks', ['status' => 'matched', 'index_omissions' => 1]);
    }

    public function test_receipt_conflict_pauses_and_preserves_the_original_event(): void
    {
        [$primary, $secondary, $cursor] = $this->pair();
        (new BlockScanner)->scan($primary);
        $secondary->setAmount(2000000);
        $this->expectException(ScanException::class);
        try {
            (new Reconciler)->reconcile($primary, $secondary);
        } finally {
            $this->assertNotNull(DB::table('scan_cursors')->find($cursor)->paused_at);
            $this->assertDatabaseHas('reconciliation_checks', ['status' => 'conflict', 'failure_code' => 'receipt_witness_disagreement']);
            $this->assertDatabaseHas('audit_events', ['action' => 'reconciliation.conflict']);
            $this->assertSame('1000000', DB::table('transfers')->sole()->amount_raw);
            $this->assertNull(DB::table('scan_ranges')->where('cursor_id', $cursor)->sole()->reconciled_at);
        }
    }

    public function test_boundary_hash_disagreement_never_advances_the_secondary(): void
    {
        [$primary, $secondary, $cursor] = $this->pair();
        (new BlockScanner)->scan($primary);
        $secondary->hashOverride = '0x'.str_repeat('f', 64);
        $this->expectException(ScanException::class);
        try {
            (new Reconciler)->reconcile($primary, $secondary);
        } finally {
            $this->assertDatabaseMissing('scan_cursors', ['role' => 'reconciliation']);
            $this->assertDatabaseHas('reconciliation_checks', ['failure_code' => 'boundary_hash_mismatch']);
            $this->assertDatabaseCount('transfers', 1);
        }
    }

    public function test_lagging_secondary_waits_for_its_own_finality_before_marking_coverage(): void
    {
        [$primary, $secondary, $cursor] = $this->pair();
        (new BlockScanner)->scan($primary);
        $secondary->head = 9;
        $result = (new Reconciler)->reconcile($primary, $secondary);
        $this->assertTrue($result->waitingForFinality);
        $this->assertDatabaseMissing('scan_cursors', ['role' => 'reconciliation']);
        $this->assertDatabaseCount('reconciliation_checks', 0);
        $this->assertNull(DB::table('scan_ranges')->where('cursor_id', $cursor)->sole()->reconciled_at);
        $secondary->head = 10;
        $this->assertFalse((new Reconciler)->reconcile($primary, $secondary)->waitingForFinality);
    }

    public function test_unavailable_history_is_not_accepted_as_empty_coverage(): void
    {
        [$primary, $secondary, $cursor] = $this->pair();
        (new BlockScanner)->scan($primary);
        $secondary->blockError = new RpcException('Historical block is temporarily unavailable.', true);
        $this->expectException(RpcException::class);
        try {
            (new Reconciler)->reconcile($primary, $secondary);
        } finally {
            $this->assertDatabaseMissing('scan_cursors', ['role' => 'reconciliation']);
            $this->assertDatabaseCount('reconciliation_checks', 0);
            $this->assertNull(DB::table('scan_ranges')->where('cursor_id', $cursor)->sole()->reconciled_at);
            $this->assertDatabaseHas('provider_health', ['provider' => 'secondary', 'failure_count' => 1]);
        }
    }

    public function test_unavailable_primary_witness_defers_repair_without_claiming_conflict(): void
    {
        [$primary, $secondary, $cursor] = $this->pair();
        $primary->omitLogs = true;
        (new BlockScanner)->scan($primary);
        $primary->receiptError = new RpcException('Receipt is not yet available.', true);
        $this->expectException(RpcException::class);
        try {
            (new Reconciler)->reconcile($primary, $secondary);
        } finally {
            $this->assertDatabaseCount('transfers', 0);
            $this->assertDatabaseCount('notification_outbox', 0);
            $this->assertDatabaseCount('reconciliation_checks', 0);
            $this->assertNull(DB::table('scan_cursors')->find($cursor)->paused_at);
        }
    }

    public function test_recovered_event_obeys_the_users_notification_floor(): void
    {
        [$primary, $secondary] = $this->pair();
        DB::table('wallet_subscriptions')->update(['notification_start_block' => 11]);
        $primary->omitLogs = true;
        (new BlockScanner)->scan($primary);
        $this->assertSame(1, (new Reconciler)->reconcile($primary, $secondary)->repairedEvents);
        $this->assertDatabaseCount('transfers', 1);
        $this->assertDatabaseCount('notification_outbox', 0);
    }

    public function test_private_backfill_ranges_do_not_become_global_live_reconciliation(): void
    {
        [$primary, $secondary] = $this->pair();
        $set = DB::table('watch_sets')->insertGetId(['chain' => 'ethereum', 'version' => 2, 'cutover_block' => -1]);
        $cursor = (new BlockScanner)->initialize('ethereum', 10, $set, 'backfill', 'backfill:1');
        DB::table('scan_cursors')->where('id', $cursor)->update(['last_block' => 10]);
        DB::table('scan_ranges')->insert(['cursor_id' => $cursor, 'watch_set_id' => $set, 'from_block' => 10, 'to_block' => 10, 'status' => 'completed']);
        $this->assertTrue((new Reconciler)->reconcile($primary, $secondary)->idle);
        $this->assertSame(0, $secondary->verifyCalls);
        $this->assertDatabaseCount('reconciliation_checks', 0);
    }

    public function test_reconciliation_keeps_the_original_watch_set_after_a_live_cutover(): void
    {
        [$primary, $secondary, $cursor, $wallet] = $this->pair();
        (new BlockScanner)->scan($primary);
        $newWallet = DB::table('wallets')->insertGetId(['chain' => 'ethereum', 'address' => '0x'.str_repeat('3', 40)]);
        $set = DB::table('watch_sets')->insertGetId(['chain' => 'ethereum', 'version' => 2, 'cutover_block' => 10, 'activated_at' => now()]);
        DB::table('watch_set_wallets')->insert(['watch_set_id' => $set, 'wallet_id' => $newWallet]);
        DB::table('scan_cursors')->where('id', $cursor)->update(['watch_set_id' => $set]);
        (new Reconciler)->reconcile($primary, $secondary);
        $this->assertSame(['0x'.str_repeat('1', 40)], $secondary->queriedAddresses[0]);
        $this->assertDatabaseCount('transfers', 1);
    }

    public function test_rolling_recheck_recovers_delayed_indexing_without_rewinding_watermark(): void
    {
        [$primary, $secondary] = $this->pair();
        $primary->omitLogs = $secondary->omitLogs = true;
        (new BlockScanner)->scan($primary);
        (new Reconciler)->reconcile($primary, $secondary);
        $this->assertDatabaseCount('transfers', 0);
        $primary->omitLogs = $secondary->omitLogs = false;
        $this->travel(16)->minutes();
        $result = (new Reconciler)->reconcile($primary, $secondary);
        $this->assertSame(1, $result->repairedEvents);
        $this->assertDatabaseCount('notification_outbox', 1);
        $this->assertSame(10, DB::table('scan_cursors')->where('role', 'reconciliation')->sole()->last_block);
        $this->assertTrue((new Reconciler)->reconcile($primary, $secondary)->idle);
    }

    public function test_repair_and_secondary_coverage_roll_back_together_on_database_failure(): void
    {
        [$primary, $secondary, $cursor] = $this->pair();
        $primary->omitLogs = true;
        (new BlockScanner)->scan($primary);
        DB::unprepared("CREATE TRIGGER reject_reconciliation BEFORE INSERT ON reconciliation_checks BEGIN SELECT RAISE(ABORT, 'Injected failure'); END");
        try {
            (new Reconciler)->reconcile($primary, $secondary);
            $this->fail('Expected transactional repair failure.');
        } catch (QueryException) {
            $this->assertDatabaseCount('transfers', 0);
            $this->assertDatabaseCount('notification_outbox', 0);
            $this->assertDatabaseMissing('scan_cursors', ['role' => 'reconciliation']);
            $this->assertNull(DB::table('scan_ranges')->where('cursor_id', $cursor)->sole()->reconciled_at);
        }
        DB::unprepared('DROP TRIGGER reject_reconciliation');
        $this->assertSame(1, (new Reconciler)->reconcile($primary, $secondary)->repairedEvents);
    }

    public function test_retryable_primary_failure_uses_the_same_cursor_and_persists_retry_after(): void
    {
        [$primary, $secondary, $cursor] = $this->pair();
        $primary->networkError = new RpcException('Rate limit.', true, 429, 3600);
        $result = (new ResilientScanner)->scan($primary, $secondary);
        $this->assertSame($cursor, $result->cursorId);
        $this->assertDatabaseCount('scan_cursors', 1);
        $this->assertDatabaseCount('transfers', 1);
        $this->assertSame(1, $primary->verifyCalls);
        $this->assertDatabaseHas('provider_health', ['provider' => 'primary', 'last_http_status' => 429, 'failure_count' => 1]);
        $opens = DB::table('provider_health')->where('provider', 'primary')->sole()->opens_until;
        $this->assertTrue(CarbonImmutable::parse($opens)->greaterThanOrEqualTo(now()->addSeconds(3599)));
        (new ResilientScanner)->scan($primary, $secondary);
        $this->assertSame(1, $primary->verifyCalls);
        $this->assertDatabaseCount('notification_outbox', 1);
    }

    public function test_provider_recovers_after_cooldown_without_unnecessary_backup_requests(): void
    {
        [$primary, $secondary] = $this->pair();
        $primary->networkError = new RpcException('Temporary outage.', true);
        (new ResilientScanner)->scan($primary, $secondary);
        $backupCalls = $secondary->verifyCalls;
        $this->travel(6)->minutes();
        $primary->networkError = null;
        $primary->head = 11;
        (new ResilientScanner)->scan($primary, $secondary);
        $this->assertSame($backupCalls, $secondary->verifyCalls);
        $this->assertDatabaseHas('provider_health', ['provider' => 'primary', 'failure_count' => 0, 'opens_until' => null]);
        $this->assertDatabaseHas('scan_cursors', ['role' => 'primary', 'last_block' => 11]);
    }

    public function test_validation_and_permission_failure_do_not_silently_switch_provider(): void
    {
        [$primary, $secondary, $cursor] = $this->pair();
        $primary->networkError = new RpcException('Wrong network or forbidden configuration.', false, 403);
        $this->expectException(RpcException::class);
        try {
            (new ResilientScanner)->scan($primary, $secondary);
        } finally {
            $this->assertSame(0, $secondary->verifyCalls);
            $this->assertSame(9, DB::table('scan_cursors')->find($cursor)->last_block);
            $this->assertDatabaseCount('transfers', 0);
        }
    }

    public function test_finalized_history_mismatch_never_uses_failover_to_hide_the_conflict(): void
    {
        [$primary, $secondary, $cursor] = $this->pair();
        (new BlockScanner)->scan($primary);
        $primary->hashOverride = '0x'.str_repeat('f', 64);
        $this->expectException(ScanException::class);
        try {
            (new ResilientScanner)->scan($primary, $secondary);
        } finally {
            $this->assertSame(0, $secondary->verifyCalls);
            $this->assertNotNull(DB::table('scan_cursors')->find($cursor)->paused_at);
            $this->assertDatabaseCount('transfers', 1);
        }
    }

    public function test_two_open_circuits_do_not_keep_hitting_exhausted_provider_quotas(): void
    {
        [$primary, $secondary] = $this->pair();
        $primary->networkError = $secondary->networkError = new RpcException('Quota exhausted.', true, 429, 300);
        try {
            (new ResilientScanner)->scan($primary, $secondary);
            $this->fail('Expected both providers to fail.');
        } catch (RpcException) {
            $this->assertSame(1, $primary->verifyCalls);
            $this->assertSame(1, $secondary->verifyCalls);
        }
        $this->expectException(ProviderCoolingDown::class);
        try {
            (new ResilientScanner)->scan($primary, $secondary);
        } finally {
            $this->assertSame(1, $primary->verifyCalls);
            $this->assertSame(1, $secondary->verifyCalls);
            $this->assertDatabaseCount('transfers', 0);
        }
    }

    public function test_manual_backup_selection_continues_the_existing_primary_cursor(): void
    {
        [$primary, $secondary, $cursor] = $this->pair();
        $result = (new ResilientScanner)->scanWithSecondary($primary, $secondary);
        $this->assertSame($cursor, $result->cursorId);
        $this->assertSame(0, $primary->verifyCalls);
        $this->assertDatabaseCount('scan_cursors', 1);
        $this->assertDatabaseHas('scan_cursors', ['id' => $cursor, 'provider' => 'primary', 'last_block' => 10]);
        $this->assertDatabaseCount('notification_outbox', 1);
    }

    public function test_in_flight_success_and_shorter_failure_cannot_shorten_mandatory_retry_after(): void
    {
        [$primary] = $this->pair();
        $circuits = new ProviderCircuitBreaker;
        $circuits->failed($primary, new RpcException('Rate limit.', true, 429, 3600));
        $deadline = DB::table('provider_health')->where('provider', 'primary')->sole()->opens_until;
        $circuits->succeeded($primary);
        $this->assertSame($deadline, DB::table('provider_health')->where('provider', 'primary')->sole()->opens_until);
        $circuits->failed($primary, new RpcException('Temporary failure.', true, 503, 15));
        $this->assertSame($deadline, DB::table('provider_health')->where('provider', 'primary')->sole()->opens_until);
        $this->expectException(ProviderCoolingDown::class);
        $circuits->assertAvailable($primary);
    }

    public function test_unknown_provider_retention_never_certifies_even_an_empty_range(): void
    {
        [$primary, $secondary, $cursor] = $this->pair();
        $primary->omitLogs = $secondary->omitLogs = true;
        (new BlockScanner)->scan($primary);
        config(['blockchain.chains.ethereum.providers.secondary.history_start_block' => null]);
        $result = (new Reconciler)->reconcile($primary, $secondary);
        $this->assertTrue($result->waitingForHistory);
        $this->assertDatabaseMissing('scan_cursors', ['role' => 'reconciliation']);
        $this->assertDatabaseCount('reconciliation_checks', 0);
        $this->assertNull(DB::table('scan_ranges')->where('cursor_id', $cursor)->sole()->reconciled_at);
        $this->assertSame(0, $secondary->verifyCalls);
    }

    public function test_configured_retention_floor_defers_old_ranges_without_advancing_coverage(): void
    {
        [$primary, $secondary, $cursor] = $this->pair();
        (new BlockScanner)->scan($primary);
        config(['blockchain.chains.ethereum.providers.secondary.history_start_block' => 11]);
        $this->assertTrue((new Reconciler)->reconcile($primary, $secondary)->waitingForHistory);
        $this->assertDatabaseMissing('scan_cursors', ['role' => 'reconciliation']);
        $this->assertNull(DB::table('scan_ranges')->where('cursor_id', $cursor)->sole()->reconciled_at);
        $this->assertSame(0, $secondary->verifyCalls);
    }

    public function test_native_tron_receipt_fallback_survives_the_reconciliation_cache_wrapper(): void
    {
        [$primary, , $cursor] = $this->pair('tron');
        $secondary = new ReconciliationNativeTronClient('secondary', 'tron');
        (new BlockScanner)->scan($primary);
        $this->assertDatabaseCount('transfers', 1);
        $result = (new Reconciler)->reconcile($primary, $secondary);
        $this->assertSame(0, $result->repairedEvents);
        $this->assertGreaterThan(0, $secondary->nativeCalls);
        $this->assertDatabaseHas('transfers', ['chain' => 'tron', 'finality' => 'solidified']);
        $this->assertNotNull(DB::table('scan_ranges')->where('cursor_id', $cursor)->sole()->reconciled_at);
    }

    public function test_http_retry_after_two_days_is_preserved_into_persisted_provider_circuit(): void
    {
        [$primary] = $this->pair();
        Http::fake(['https://provider.test/rpc' => Http::response([], 429, ['Retry-After' => '172800'])]);
        $transport = new RpcTransport(['rpc_url' => 'https://provider.test/rpc', 'attempts' => 2, 'max_backoff_ms' => 1000]);
        try {
            $transport->rpc('eth_chainId', []);
            $this->fail('Expected the long server-requested delay.');
        } catch (RpcException $error) {
            $this->assertSame(172800, $error->retryAfterSeconds);
            (new ProviderCircuitBreaker)->failed($primary, $error);
        }
        $deadline = DB::table('provider_health')->where('provider', 'primary')->sole()->opens_until;
        $this->assertTrue(CarbonImmutable::parse($deadline)->greaterThanOrEqualTo(now()->addSeconds(172799)));
        Http::assertSentCount(1);
    }

    public function test_http_date_retry_after_beyond_one_day_is_preserved_end_to_end(): void
    {
        [$primary] = $this->pair();
        $target = time() + 172800;
        $header = gmdate('D, d M Y H:i:s', $target).' GMT';
        Http::fake(['https://provider.test/rpc' => Http::response([], 503, ['Retry-After' => $header])]);
        $transport = new RpcTransport(['rpc_url' => 'https://provider.test/rpc', 'attempts' => 2, 'max_backoff_ms' => 1000]);
        try {
            $transport->rpc('eth_chainId', []);
            $this->fail('Expected the long server-requested date.');
        } catch (RpcException $error) {
            $this->assertGreaterThanOrEqual(172798, $error->retryAfterSeconds);
            (new ProviderCircuitBreaker)->failed($primary, $error);
        }
        $deadline = DB::table('provider_health')->where('provider', 'primary')->sole()->opens_until;
        $this->assertTrue(CarbonImmutable::parse($deadline)->getTimestamp() >= $target - 1);
        Http::assertSentCount(1);
    }

    public function test_unconfigured_reconciliation_command_fails_without_rpc_access(): void
    {
        config(['blockchain.chains.ethereum.providers.secondary.rpc_url' => null]);
        $this->artisan('chain:reconcile ethereum --once')->assertFailed();
    }

    private function pair(string $chain = 'ethereum'): array
    {
        config(['blockchain.chains.'.$chain.'.providers.primary.history_start_block' => 0, 'blockchain.chains.'.$chain.'.providers.secondary.history_start_block' => 0]);
        $user = DB::table('users')->insertGetId(['telegram_user_id' => '1001']);
        $key = DB::table('access_keys')->insertGetId(['token_hash' => str_repeat('a', 64), 'expires_at' => now()->addDay()]);
        DB::table('access_grants')->insert(['user_id' => $user, 'access_key_id' => $key]);
        $wallet = DB::table('wallets')->insertGetId(['chain' => $chain, 'address' => AddressCodec::normalize($chain, '0x'.str_repeat('1', 40))]);
        $userWallet = DB::table('user_wallets')->insertGetId(['user_id' => $user, 'wallet_id' => $wallet, 'start_block' => 10]);
        $destination = DB::table('chat_destinations')->insertGetId(['chat_id' => '1001']);
        DB::table('user_chat_destinations')->insert(['user_id' => $user, 'destination_id' => $destination]);
        DB::table('wallet_subscriptions')->insert(['user_id' => $user, 'user_wallet_id' => $userWallet, 'destination_id' => $destination, 'notification_start_block' => 10]);
        $set = DB::table('watch_sets')->insertGetId(['chain' => $chain, 'version' => 1, 'cutover_block' => 9, 'activated_at' => now()]);
        DB::table('watch_set_wallets')->insert(['watch_set_id' => $set, 'wallet_id' => $wallet]);
        $cursor = (new BlockScanner)->initialize($chain, 10, $set);

        return [new ReconciliationFakeClient('primary', $chain), new ReconciliationFakeClient('secondary', $chain), $cursor, $wallet];
    }
}

class ReconciliationFakeClient implements ChainClient
{
    public int $head = 10;

    public bool $omitLogs = false;

    public ?RpcException $networkError = null;

    public ?RpcException $receiptError = null;

    public ?RpcException $blockError = null;

    public ?string $hashOverride = null;

    public int $verifyCalls = 0;

    public int $receiptCalls = 0;

    public array $queriedAddresses = [];

    public array $log;

    public function __construct(private readonly string $role, private readonly string $network = 'ethereum')
    {
        $this->log = [
            'address' => $network === 'tron' ? TronRpcClient::USDT_CONTRACT : EthereumRpcClient::USDT_CONTRACT,
            'topics' => [EthereumRpcClient::TRANSFER_TOPIC, '0x'.str_repeat('0', 24).str_repeat('1', 40), '0x'.str_repeat('0', 24).str_repeat('2', 40)],
            'data' => '0x'.str_pad(dechex(1000000), 64, '0', STR_PAD_LEFT),
            'transactionHash' => '0x'.str_repeat('a', 64), 'blockNumber' => '0xa', 'blockHash' => '0x'.str_repeat('b', 64), 'logIndex' => '0x3',
        ];
    }

    public function chain(): string
    {
        return $this->network;
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
        return ['number' => $this->head, 'hash' => $this->hashOverride ?? '0x'.str_repeat('b', 64), 'timestamp' => 1793030400];
    }

    public function block(int $number): array
    {
        if ($this->blockError !== null) {
            throw $this->blockError;
        }

        return ['number' => $number, 'hash' => $this->hashOverride ?? '0x'.str_repeat('b', 64), 'timestamp' => 1793030400];
    }

    public function logs(int $from, int $to, array $normalizedWatchedAddresses, string $direction): array
    {
        $this->queriedAddresses[] = $normalizedWatchedAddresses;
        if ($this->omitLogs || $from > 10 || $to < 10) {
            return [];
        }
        $address = AddressCodec::normalize($this->network, '0x'.substr($this->log['topics'][$direction === 'outgoing' ? 1 : 2], -40));

        return in_array($address, $normalizedWatchedAddresses, true) ? [$this->log] : [];
    }

    public function receipt(string $transactionHash): array
    {
        $this->receiptCalls++;
        if ($this->receiptError !== null) {
            throw $this->receiptError;
        }

        return [
            'transactionHash' => $transactionHash, 'blockHash' => $this->log['blockHash'], 'blockNumber' => '0xa', 'status' => '0x1',
            'logs' => [$this->log],
        ];
    }

    public function setAmount(int $amount): void
    {
        $this->log['data'] = '0x'.str_pad(dechex($amount), 64, '0', STR_PAD_LEFT);
    }
}

class ReconciliationNativeTronClient extends ReconciliationFakeClient
{
    public int $nativeCalls = 0;

    public function logs(int $from, int $to, array $normalizedWatchedAddresses, string $direction): array
    {
        throw new RangeLimitException;
    }

    public function solidifiedBlockReceipts(int $number): array
    {
        $this->nativeCalls++;

        return [$this->receipt($this->log['transactionHash'])];
    }
}
