<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Blockchain\AddressCodec;
use App\Blockchain\Contracts\ChainClient;
use App\Blockchain\EthereumRpcClient;
use App\Blockchain\Exceptions\RangeLimitException;
use App\Blockchain\TronRpcClient;
use App\Domain\Amount;
use App\Monitoring\BlockScanner;
use App\Monitoring\OutboxRouter;
use App\Monitoring\ScanException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class MonitoringScannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_atomic_scan_preserves_full_receipt_positions_and_deduplicates_both_directions(): void
    {
        $sender = $this->address('ethereum', 1);
        $recipient = $this->address('ethereum', 2);
        $this->member('1001', 'ethereum', $sender);
        $this->member('1002', 'ethereum', $recipient);
        $client = $this->client('ethereum', $sender, $recipient);
        // Position zero is unrelated. Two otherwise identical transfers must both survive.
        $client->receipts[$client->transaction]['logs'] = [
            ['address' => $this->address('ethereum', 99), 'topics' => [], 'data' => '0x'],
            $client->log, $client->log,
        ];
        $cursor = $this->initialize($client);
        $result = (new BlockScanner)->scan($client);

        $this->assertSame(2, $result->events);
        $this->assertDatabaseCount('transfers', 2);
        $this->assertSame([1, 2], DB::table('transfers')->orderBy('receipt_log_position')->pluck('receipt_log_position')->all());
        $this->assertDatabaseCount('notification_outbox', 4);
        $this->assertDatabaseCount('outbox_authorizations', 4);
        $this->assertSame(10, DB::table('scan_cursors')->find($cursor)->last_block);
        $this->assertDatabaseHas('scan_ranges', ['cursor_id' => $cursor, 'status' => 'completed']);
        $this->assertTrue((new BlockScanner)->scan($client)->idle);
        $this->assertDatabaseCount('notification_outbox', 4);
    }

    public function test_missing_receipt_leaves_event_outbox_and_coverage_unwritten(): void
    {
        $sender = $this->address('ethereum', 1);
        $this->member('1001', 'ethereum', $sender);
        $client = $this->client('ethereum', $sender, $this->address('ethereum', 2));
        $client->receiptFailure = true;
        $cursor = $this->initialize($client);
        try {
            (new BlockScanner)->scan($client);
            $this->fail('Expected an unavailable receipt to block coverage.');
        } catch (RuntimeException) {
            $this->assertSame(9, DB::table('scan_cursors')->find($cursor)->last_block);
            $this->assertDatabaseCount('transfers', 0);
            $this->assertDatabaseCount('notification_outbox', 0);
            $this->assertDatabaseCount('scan_ranges', 0);
        }
        $client->receiptFailure = false;
        (new BlockScanner)->scan($client);
        $this->assertDatabaseCount('transfers', 1);
        $this->assertDatabaseCount('notification_outbox', 1);
    }

    public function test_database_failure_after_event_and_outbox_write_rolls_back_cursor_too(): void
    {
        $sender = $this->address('ethereum', 1);
        $this->member('1001', 'ethereum', $sender);
        $client = $this->client('ethereum', $sender, $this->address('ethereum', 2));
        $cursor = $this->initialize($client);
        DB::unprepared("CREATE TRIGGER reject_range BEFORE INSERT ON scan_ranges BEGIN SELECT RAISE(ABORT, 'Injected commit failure'); END");
        try {
            (new BlockScanner)->scan($client);
            $this->fail('Expected the range insert to fail.');
        } catch (QueryException) {
            $this->assertDatabaseCount('transfers', 0);
            $this->assertDatabaseCount('notification_outbox', 0);
            $this->assertDatabaseCount('outbox_authorizations', 0);
            $this->assertSame(9, DB::table('scan_cursors')->find($cursor)->last_block);
        }
        DB::unprepared('DROP TRIGGER reject_range');
        (new BlockScanner)->scan($client);
        $this->assertDatabaseCount('notification_outbox', 1);
    }

    public function test_watch_set_cutover_during_http_work_prevents_old_snapshot_commit(): void
    {
        $sender = $this->address('ethereum', 1);
        $this->member('1001', 'ethereum', $sender);
        $client = $this->client('ethereum', $sender, $this->address('ethereum', 2));
        $cursor = $this->initialize($client);
        $set = DB::table('watch_sets')->insertGetId(['chain' => 'ethereum', 'version' => 2, 'cutover_block' => 9, 'activated_at' => now()]);
        $client->duringReceipt = static function () use ($cursor, $set): void {
            DB::table('scan_cursors')->where('id', $cursor)->update(['watch_set_id' => $set]);
        };
        $this->expectException(ScanException::class);
        try {
            (new BlockScanner)->scan($client);
        } finally {
            $this->assertDatabaseCount('transfers', 0);
            $this->assertSame(9, DB::table('scan_cursors')->find($cursor)->last_block);
            $this->assertSame($set, DB::table('scan_cursors')->find($cursor)->watch_set_id);
        }
    }

    public function test_replay_uses_existing_event_and_outbox_without_duplicates(): void
    {
        $sender = $this->address('ethereum', 1);
        $this->member('1001', 'ethereum', $sender);
        $client = $this->client('ethereum', $sender, $this->address('ethereum', 2));
        $cursor = $this->initialize($client);
        $scanner = new BlockScanner;
        $scanner->scan($client);
        DB::table('scan_cursors')->where('id', $cursor)->update(['last_block' => 9, 'last_block_hash' => null]);
        $scanner->scan($client);
        $this->assertDatabaseCount('transfers', 1);
        $this->assertDatabaseCount('notification_outbox', 1);
        $this->assertDatabaseCount('outbox_authorizations', 1);
        $this->assertDatabaseCount('scan_ranges', 1);
    }

    public function test_one_user_does_not_receive_another_users_history_backfill(): void
    {
        $sender = $this->address('ethereum', 1);
        $alice = $this->member('1001', 'ethereum', $sender);
        $this->member('1002', 'ethereum', $sender);
        $client = $this->client('ethereum', $sender, $this->address('ethereum', 2));
        $cursor = $this->initialize($client);
        (new BlockScanner)->scanCursor($client, $cursor, 100, true, $alice['user_wallet_id'], 10);
        $this->assertDatabaseCount('notification_outbox', 1);
        $this->assertSame($alice['user_id'], DB::table('outbox_authorizations')->sole()->user_id);
    }

    public function test_normal_routing_honors_notification_floor_independently_of_history_start(): void
    {
        $sender = $this->address('ethereum', 1);
        $owner = $this->member('1001', 'ethereum', $sender);
        DB::table('wallet_subscriptions')->where('id', $owner['subscription_id'])->update(['notification_start_block' => 11]);
        $client = $this->client('ethereum', $sender, $this->address('ethereum', 2));
        $this->initialize($client);
        (new BlockScanner)->scan($client);
        $this->assertDatabaseCount('transfers', 1);
        $this->assertDatabaseCount('notification_outbox', 0);
    }

    public function test_explicit_scoped_notifying_backfill_persists_the_floor_override(): void
    {
        $sender = $this->address('ethereum', 1);
        $owner = $this->member('1001', 'ethereum', $sender);
        $this->member('1002', 'ethereum', $sender);
        DB::table('wallet_subscriptions')->update(['notification_start_block' => 11]);
        $client = $this->client('ethereum', $sender, $this->address('ethereum', 2));
        $cursor = $this->initialize($client);
        DB::table('scan_cursors')->where('id', $cursor)->update(['role' => 'backfill', 'provider' => 'backfill:1']);
        (new BlockScanner)->scanCursor($client, $cursor, 100, true, $owner['user_wallet_id'], 10, true);
        $this->assertDatabaseCount('notification_outbox', 1);
        $outbox = (int) DB::table('notification_outbox')->sole()->id;
        $this->assertDatabaseHas('outbox_authorizations', ['outbox_id' => $outbox, 'user_id' => $owner['user_id'], 'historical_override' => true]);
        $this->assertCount(1, (new OutboxRouter)->authorizedRoutes($outbox));
        DB::table('access_grants')->where('id', $owner['grant_id'])->update(['revoked_at' => now()]);
        $this->assertCount(0, (new OutboxRouter)->authorizedRoutes($outbox));
    }

    public function test_floor_override_cannot_be_used_for_an_ordinary_live_cursor(): void
    {
        $sender = $this->address('ethereum', 1);
        $owner = $this->member('1001', 'ethereum', $sender);
        $client = $this->client('ethereum', $sender, $this->address('ethereum', 2));
        $cursor = $this->initialize($client);
        $this->expectException(ScanException::class);
        try {
            (new BlockScanner)->scanCursor($client, $cursor, 100, true, $owner['user_wallet_id'], 10, true);
        } finally {
            $this->assertDatabaseCount('transfers', 0);
            $this->assertDatabaseCount('notification_outbox', 0);
        }
    }

    public function test_silent_backfill_persists_ledger_without_notifications(): void
    {
        $sender = $this->address('ethereum', 1);
        $this->member('1001', 'ethereum', $sender);
        $client = $this->client('ethereum', $sender, $this->address('ethereum', 2));
        $cursor = $this->initialize($client);
        (new BlockScanner)->scanCursor($client, $cursor, 100, false);
        $this->assertDatabaseCount('transfers', 1);
        $this->assertDatabaseCount('notification_outbox', 0);
    }

    public function test_two_directions_are_required_and_finality_caps_the_range(): void
    {
        $sender = $this->address('ethereum', 1);
        $this->member('1001', 'ethereum', $sender);
        $client = $this->client('ethereum', $sender, $this->address('ethereum', 2));
        $client->head = 9;
        $cursor = $this->initialize($client);
        $this->assertTrue((new BlockScanner)->scan($client)->idle);
        $this->assertDatabaseCount('transfers', 0);
        $client->head = 10;
        (new BlockScanner)->scan($client);
        $this->assertSame(['outgoing', 'incoming'], array_column($client->queries, 'direction'));
        foreach ($client->queries as $query) {
            $this->assertLessThanOrEqual(10, $query['to']);
        }
        $this->assertSame(10, DB::table('scan_cursors')->find($cursor)->last_block);
    }

    public function test_range_limit_is_split_and_no_unfinished_direction_is_skipped(): void
    {
        $sender = $this->address('ethereum', 1);
        $this->member('1001', 'ethereum', $sender);
        $client = $this->client('ethereum', $sender, $this->address('ethereum', 2));
        $client->head = 12;
        $client->rangeLimit = 1;
        $cursor = $this->initialize($client);
        (new BlockScanner)->scan($client, 100);
        $this->assertDatabaseCount('transfers', 1);
        $this->assertSame(12, DB::table('scan_cursors')->find($cursor)->last_block);
        $this->assertGreaterThan(2, count($client->queries));
    }

    public function test_unsplittable_ethereum_result_is_visible_and_never_advances(): void
    {
        $sender = $this->address('ethereum', 1);
        $this->member('1001', 'ethereum', $sender);
        $client = $this->client('ethereum', $sender, $this->address('ethereum', 2));
        $client->rangeLimit = 0;
        $cursor = $this->initialize($client);
        $this->expectException(ScanException::class);
        try {
            (new BlockScanner)->scan($client);
        } finally {
            $this->assertSame(9, DB::table('scan_cursors')->find($cursor)->last_block);
            $this->assertDatabaseCount('transfers', 0);
        }
    }

    public function test_tron_solidified_receipt_positions_and_addresses_are_normalized(): void
    {
        $sender = $this->address('tron', 1);
        $recipient = $this->address('tron', 2);
        $this->member('1001', 'tron', $sender);
        $client = $this->client('tron', $sender, $recipient);
        $this->initialize($client);
        (new BlockScanner)->scan($client);
        $this->assertDatabaseHas('transfers', [
            'chain' => 'tron', 'from_address' => $sender, 'to_address' => $recipient,
            'contract_address' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t', 'finality' => 'solidified',
        ]);
    }

    public function test_failed_tron_does_not_prevent_ethereum_progress(): void
    {
        $eth = $this->address('ethereum', 1);
        $tron = $this->address('tron', 1);
        $this->member('1001', 'ethereum', $eth);
        $this->member('1001', 'tron', $tron);
        $ethereum = $this->client('ethereum', $eth, $this->address('ethereum', 2));
        $trc = $this->client('tron', $tron, $this->address('tron', 2));
        $trc->receiptFailure = true;
        $this->initialize($ethereum);
        $this->initialize($trc);
        try {
            (new BlockScanner)->scan($trc);
        } catch (RuntimeException) {
            // Only the failing chain remains unfinished.
        }
        (new BlockScanner)->scan($ethereum);
        $this->assertDatabaseHas('scan_cursors', ['chain' => 'ethereum', 'last_block' => 10]);
        $this->assertDatabaseHas('scan_cursors', ['chain' => 'tron', 'last_block' => 9]);
    }

    public function test_self_transfer_and_two_subscriptions_to_one_chat_enqueue_once(): void
    {
        $address = $this->address('ethereum', 1);
        $this->member('1001', 'ethereum', $address);
        $client = $this->client('ethereum', $address, $address);
        $this->initialize($client);
        (new BlockScanner)->scan($client);
        $this->assertDatabaseCount('transfers', 1);
        $this->assertDatabaseCount('notification_outbox', 1);
    }

    public function test_two_watched_wallets_for_one_destination_generate_one_message(): void
    {
        $sender = $this->address('ethereum', 1);
        $recipient = $this->address('ethereum', 2);
        $this->member('1001', 'ethereum', $sender);
        $this->member('1001', 'ethereum', $recipient);
        $client = $this->client('ethereum', $sender, $recipient);
        $this->initialize($client);
        (new BlockScanner)->scan($client);
        $this->assertDatabaseCount('transfers', 1);
        $this->assertDatabaseCount('notification_outbox', 1);
        $this->assertCount(4, (new OutboxRouter)->authorizedRoutes((int) DB::table('notification_outbox')->sole()->id));
    }

    public function test_zero_and_max_uint256_receipt_events_keep_exact_values(): void
    {
        $sender = $this->address('ethereum', 1);
        $this->member('1001', 'ethereum', $sender);
        $client = $this->client('ethereum', $sender, $this->address('ethereum', 2));
        $client->log['data'] = '0x'.str_repeat('0', 64);
        $maximum = $client->log;
        $maximum['data'] = '0x'.str_repeat('f', 64);
        $client->receipts[$client->transaction]['logs'] = [$client->log, $maximum];
        $this->initialize($client);
        (new BlockScanner)->scan($client);
        $this->assertSame(['0', Amount::MAX_UINT256], DB::table('transfers')->orderBy('receipt_log_position')->pluck('amount_raw')->all());
        $this->assertDatabaseCount('notification_outbox', 2);
    }

    public function test_tron_single_block_result_limit_uses_solidified_receipt_fallback(): void
    {
        $sender = $this->address('tron', 1);
        $this->member('1001', 'tron', $sender);
        $client = new MonitoringTronFallbackClient('tron', $sender, $this->address('tron', 2));
        $client->rangeLimit = 0;
        $cursor = $this->initialize($client);
        (new BlockScanner)->scan($client);
        $this->assertDatabaseCount('transfers', 1);
        $this->assertDatabaseCount('notification_outbox', 1);
        $this->assertSame(10, DB::table('scan_cursors')->find($cursor)->last_block);
        $this->assertGreaterThan(0, $client->nativeCalls);
    }

    public function test_failed_execution_receipt_does_not_create_a_confirmed_transfer(): void
    {
        $sender = $this->address('ethereum', 1);
        $this->member('1001', 'ethereum', $sender);
        $client = $this->client('ethereum', $sender, $this->address('ethereum', 2));
        $client->receipts[$client->transaction]['status'] = '0x0';
        $cursor = $this->initialize($client);
        $this->expectException(ScanException::class);
        try {
            (new BlockScanner)->scan($client);
        } finally {
            $this->assertDatabaseCount('transfers', 0);
            $this->assertSame(9, DB::table('scan_cursors')->find($cursor)->last_block);
        }
    }

    public function test_filter_changes_and_revoked_access_are_revalidated_before_delivery(): void
    {
        $sender = $this->address('ethereum', 1);
        $member = $this->member('1001', 'ethereum', $sender);
        $client = $this->client('ethereum', $sender, $this->address('ethereum', 2));
        $this->initialize($client);
        (new BlockScanner)->scan($client);
        $router = new OutboxRouter;
        $outbox = (int) DB::table('notification_outbox')->sole()->id;
        $this->assertCount(1, $router->authorizedRoutes($outbox));
        DB::table('wallet_subscriptions')->where('id', $member['subscription_id'])->update(['direction' => 'incoming']);
        $this->assertCount(0, $router->authorizedRoutes($outbox));
        DB::table('wallet_subscriptions')->where('id', $member['subscription_id'])->update(['direction' => 'both', 'min_amount' => '1000001']);
        $this->assertCount(0, $router->authorizedRoutes($outbox));
        DB::table('wallet_subscriptions')->where('id', $member['subscription_id'])->update(['min_amount' => '0']);
        DB::table('access_grants')->where('id', $member['grant_id'])->update(['revoked_at' => now()]);
        $this->assertCount(0, $router->authorizedRoutes($outbox));
    }

    public function test_fake_usdt_logs_do_not_create_a_transfer(): void
    {
        $sender = $this->address('ethereum', 1);
        $this->member('1001', 'ethereum', $sender);
        $client = $this->client('ethereum', $sender, $this->address('ethereum', 2));
        $client->log['address'] = $this->address('ethereum', 99);
        $this->initialize($client);
        (new BlockScanner)->scan($client);
        $this->assertDatabaseCount('transfers', 0);
        $this->assertDatabaseCount('notification_outbox', 0);
    }

    public function test_finalized_boundary_mismatch_pauses_instead_of_rewriting_history(): void
    {
        $sender = $this->address('ethereum', 1);
        $this->member('1001', 'ethereum', $sender);
        $client = $this->client('ethereum', $sender, $this->address('ethereum', 2));
        $cursor = $this->initialize($client);
        (new BlockScanner)->scan($client);
        $client->hashOverride = '0x'.str_repeat('f', 64);
        $this->expectException(ScanException::class);
        try {
            (new BlockScanner)->scan($client);
        } finally {
            $this->assertNotNull(DB::table('scan_cursors')->find($cursor)->paused_at);
            $this->assertDatabaseCount('transfers', 1);
        }
    }

    public function test_unconfigured_command_fails_without_making_requests(): void
    {
        config(['blockchain.chains.ethereum.providers.primary.rpc_url' => null]);
        $this->artisan('chain:scan ethereum --once')->assertFailed();
    }

    private function address(string $chain, int $number): string
    {
        return AddressCodec::normalize($chain, '0x'.str_pad(dechex($number), 40, '0', STR_PAD_LEFT));
    }

    private function initialize(MonitoringFakeClient $client): int
    {
        $set = DB::table('watch_sets')->insertGetId(['chain' => $client->chain(), 'version' => 1, 'cutover_block' => 9, 'activated_at' => now()]);
        foreach (DB::table('wallets')->where('chain', $client->chain())->pluck('id') as $wallet) {
            DB::table('watch_set_wallets')->insert(['watch_set_id' => $set, 'wallet_id' => $wallet]);
        }

        return (new BlockScanner)->initialize($client->chain(), 10, $set);
    }

    private function client(string $chain, string $sender, string $recipient): MonitoringFakeClient
    {
        return new MonitoringFakeClient($chain, $sender, $recipient);
    }

    private function member(string $telegramId, string $chain, string $address): array
    {
        $user = DB::table('users')->where('telegram_user_id', $telegramId)->value('id')
            ?? DB::table('users')->insertGetId(['telegram_user_id' => $telegramId]);
        $wallet = DB::table('wallets')->where(compact('chain', 'address'))->value('id')
            ?? DB::table('wallets')->insertGetId(compact('chain', 'address'));
        $userWallet = DB::table('user_wallets')->insertGetId(['user_id' => $user, 'wallet_id' => $wallet, 'label' => 'Private '.$telegramId, 'start_block' => 10]);
        $key = DB::table('access_keys')->insertGetId(['token_hash' => hash('sha256', $telegramId.$chain.$address), 'expires_at' => now()->addDay()]);
        $grant = DB::table('access_grants')->insertGetId(['user_id' => $user, 'access_key_id' => $key]);
        $destination = DB::table('chat_destinations')->where('chat_id', $telegramId)->value('id')
            ?? DB::table('chat_destinations')->insertGetId(['chat_id' => $telegramId]);
        DB::table('user_chat_destinations')->insertOrIgnore(['user_id' => $user, 'destination_id' => $destination]);
        $subscription = DB::table('wallet_subscriptions')->insertGetId(['user_id' => $user, 'user_wallet_id' => $userWallet, 'destination_id' => $destination]);

        return ['user_id' => (int) $user, 'user_wallet_id' => $userWallet, 'subscription_id' => $subscription, 'grant_id' => $grant];
    }
}

class MonitoringFakeClient implements ChainClient
{
    public int $head = 10;

    public int $rangeLimit = 100;

    public bool $receiptFailure = false;

    public ?string $hashOverride = null;

    public mixed $duringReceipt = null;

    public array $queries = [];

    public array $receipts;

    public array $log;

    public string $transaction;

    public function __construct(private string $network, string $sender, string $recipient)
    {
        $this->transaction = '0x'.str_repeat($network === 'tron' ? 'b' : 'a', 64);
        $this->log = [
            'address' => $network === 'tron' ? TronRpcClient::USDT_CONTRACT : EthereumRpcClient::USDT_CONTRACT,
            'topics' => [EthereumRpcClient::TRANSFER_TOPIC, AddressCodec::topic($network, $sender), AddressCodec::topic($network, $recipient)],
            'data' => '0x'.str_pad(dechex(1000000), 64, '0', STR_PAD_LEFT),
            'transactionHash' => $this->transaction, 'blockNumber' => '0xa', 'blockHash' => $this->block(10)['hash'], 'logIndex' => '0x0',
        ];
        $this->receipts = [$this->transaction => [
            'transactionHash' => $this->transaction, 'blockNumber' => '0xa', 'blockHash' => $this->block(10)['hash'], 'status' => '0x1', 'logs' => [$this->log],
        ]];
    }

    public function chain(): string
    {
        return $this->network;
    }

    public function provider(): string
    {
        return 'primary';
    }

    public function verifyNetwork(): void {}

    public function finalizedBlock(): array
    {
        return $this->block($this->head);
    }

    public function block(int $number): array
    {
        return ['number' => $number, 'hash' => $this->hashOverride ?? '0x'.str_pad(dechex($number), 64, '0', STR_PAD_LEFT), 'timestamp' => 1793030400 + $number];
    }

    public function logs(int $from, int $to, array $normalizedWatchedAddresses, string $direction): array
    {
        $this->queries[] = ['from' => $from, 'to' => $to, 'direction' => $direction];
        if ($to - $from + 1 > $this->rangeLimit) {
            throw new RangeLimitException;
        }
        if ($from > 10 || $to < 10) {
            return [];
        }
        $position = $direction === 'outgoing' ? 1 : 2;
        $topics = array_map(fn (string $address): string => AddressCodec::topic($this->network, $address), $normalizedWatchedAddresses);

        return in_array($this->log['topics'][$position], $topics, true) ? [$this->log] : [];
    }

    public function receipt(string $transactionHash): array
    {
        if ($this->receiptFailure) {
            throw new RuntimeException('Receipt temporarily unavailable.');
        }
        if (is_callable($this->duringReceipt)) {
            ($this->duringReceipt)();
            $this->duringReceipt = null;
        }

        return $this->receipts[$transactionHash];
    }
}

class MonitoringTronFallbackClient extends MonitoringFakeClient
{
    public int $nativeCalls = 0;

    public function solidifiedBlockReceipts(int $number): array
    {
        $this->nativeCalls++;

        return array_values($this->receipts);
    }
}
