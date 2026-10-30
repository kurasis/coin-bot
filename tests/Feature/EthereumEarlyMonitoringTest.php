<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Access\AccessService;
use App\Blockchain\Exceptions\RpcException;
use App\Monitoring\BlockScanner;
use App\Monitoring\EthereumEarlyScanner;
use App\Monitoring\OutboxRouter;
use App\Monitoring\ScanException;
use App\Telegram\OutboxWorker;
use App\Wallets\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\EarlyChainClient;
use Tests\TestCase;

class EthereumEarlyMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config(['ethereum.early_enabled' => true, 'ethereum.confirmations' => 6,
            'ethereum.reorg_window' => 128, 'telegram.token' => 'synthetic-early-token']);
    }

    public function test_disabled_early_mode_performs_no_external_reads_or_writes(): void
    {
        config(['ethereum.early_enabled' => false]);
        $client = new EarlyChainClient;
        $result = app(EthereumEarlyScanner::class)->scan($client);

        $this->assertTrue($result->idle);
        $this->assertSame([], $client->requests);
        $this->assertDatabaseCount('ethereum_early_state', 0);
        $this->assertDatabaseCount('ethereum_early_events', 0);
    }

    public function test_first_enable_records_a_live_boundary_without_historical_early_notifications(): void
    {
        $this->member('91001', 'Alice');
        $client = $this->client();
        $client->addEvent(8, EarlyChainClient::address(2), EarlyChainClient::address(1), seed: 'old');
        $primary = DB::table('scan_cursors')->sole();

        $result = app(EthereumEarlyScanner::class)->scan($client);

        $this->assertTrue($result->idle);
        $this->assertSame(9, (int) DB::table('ethereum_early_state')->sole()->last_block);
        $this->assertSame($primary->last_block, DB::table('scan_cursors')->sole()->last_block);
        $this->assertDatabaseCount('ethereum_early_events', 0);
        $this->assertDatabaseCount('transfers', 0);
        $this->assertDatabaseCount('notification_outbox', 0);
    }

    public function test_idle_poll_reads_the_durable_boundary_only_once_and_still_detects_a_reorg(): void
    {
        $this->member('91001', 'Alice');
        $client = $this->client();
        $this->prime($client);
        $client->requests = [];

        $this->assertTrue(app(EthereumEarlyScanner::class)->scan($client)->idle);
        $this->assertSame(['network', 'latest', 'block:15', 'block:9'], $client->requests);

        $client->reorganize(9);
        try {
            app(EthereumEarlyScanner::class)->scan($client);
            $this->fail('An idle reorg must still require independent evidence.');
        } catch (ScanException) {
            $this->assertTrue((bool) DB::table('ethereum_early_state')->sole()->reorg_pending);
            $this->assertDatabaseCount('ethereum_early_events', 0);
            $this->assertDatabaseCount('notification_outbox', 0);
        }
    }

    public function test_productive_poll_rechecks_its_confirmation_anchor_after_event_collection(): void
    {
        $this->member('91001', 'Alice');
        $client = $this->client();
        $this->prime($client);
        $client->latestHead = 17;
        $changed = false;
        $client->duringBlock = function (int $height) use ($client, &$changed): void {
            if ($height === 11 && ! $changed) {
                $changed = true;
                $client->reorganize(11, false);
            }
        };

        try {
            app(EthereumEarlyScanner::class)->scan($client);
            $this->fail('A reorg during collection must reject the snapshot.');
        } catch (ScanException) {
            $this->assertSame(9, (int) DB::table('ethereum_early_state')->sole()->last_block);
            $this->assertTrue((bool) DB::table('ethereum_early_state')->sole()->reorg_pending);
            $this->assertDatabaseCount('ethereum_early_events', 0);
            $this->assertDatabaseCount('notification_outbox', 0);
        }
    }

    public function test_six_block_early_delivery_is_preliminary_and_does_not_advance_finalized_coverage(): void
    {
        $this->member('91001', 'Alice private label');
        $client = $this->client();
        $this->prime($client);
        $before = DB::table('scan_cursors')->sole();

        $client->latestHead = 16;
        $result = app(EthereumEarlyScanner::class)->scan($client);

        $this->assertSame(1, $result->events);
        $this->assertSame(10, $result->fromBlock);
        $this->assertSame(10, $result->toBlock);
        $this->assertDatabaseCount('ethereum_early_events', 1);
        $early = DB::table('ethereum_early_events')->sole();
        $this->assertSame(1, (int) $early->receipt_log_position);
        $this->assertSame(6, (int) $early->confirmations);
        $this->assertSame('observed', $early->state);
        $this->assertDatabaseCount('transfers', 0);
        $this->assertDatabaseCount('scan_ranges', 0);
        $this->assertSame($before->last_block, DB::table('scan_cursors')->sole()->last_block);
        $outbox = DB::table('notification_outbox')->sole();
        $this->assertNull($outbox->transfer_id);
        $this->assertSame((int) $early->id, (int) $outbox->early_event_id);
        $this->assertDatabaseCount('outbox_authorizations', 1);

        $this->fakeSend();
        $this->assertTrue(app(OutboxWorker::class)->runOnce());
        Http::assertSentCount(1);
        $text = Http::recorded()->sole()[0]['text'];
        $this->assertMatchesRegularExpression('/preliminary|provisional/i', $text);
        $this->assertStringContainsString('6', $text);
        $this->assertStringContainsString('Alice private label', $text);
        $this->assertStringNotContainsString('Status: Finalized', $text);
        $this->assertDatabaseHas('notification_outbox', ['id' => $outbox->id, 'status' => 'sent']);
        $this->assertTrue(app(EthereumEarlyScanner::class)->scan($client)->idle);
        $this->assertDatabaseCount('notification_outbox', 1);
    }

    public function test_finalized_arrival_promotes_the_same_sent_outbox_without_duplicate_delivery(): void
    {
        $this->member('91001', 'Alice');
        $client = $this->queueEarly();
        $this->fakeSend();
        app(OutboxWorker::class)->runOnce();
        $outboxId = (int) DB::table('notification_outbox')->sole()->id;

        $client->finalizedHead = 10;
        (new BlockScanner)->scan($client);

        $this->assertDatabaseCount('transfers', 1);
        $canonical = DB::table('transfers')->sole();
        $this->assertSame('finalized', $canonical->finality);
        $this->assertDatabaseCount('notification_outbox', 1);
        $this->assertDatabaseHas('notification_outbox', ['id' => $outboxId, 'transfer_id' => $canonical->id, 'status' => 'sent']);
        $this->assertDatabaseHas('ethereum_early_events', ['canonical_transfer_id' => $canonical->id, 'state' => 'finalized']);
        $this->assertFalse(app(OutboxWorker::class)->runOnce());
        Http::assertSentCount(1);
    }

    public function test_finalization_before_dispatch_sends_one_finalized_message(): void
    {
        $this->member('91001', 'Alice');
        $client = $this->queueEarly();
        $client->finalizedHead = 10;
        (new BlockScanner)->scan($client);
        $this->fakeSend();

        app(OutboxWorker::class)->runOnce();

        Http::assertSentCount(1);
        $this->assertStringContainsString('Status: Finalized', Http::recorded()->sole()[0]['text']);
        $this->assertDatabaseCount('notification_outbox', 1);
        $this->assertDatabaseCount('transfers', 1);
    }

    public function test_promoted_canonical_delivery_is_not_blocked_by_an_early_only_pause(): void
    {
        $this->member('91001', 'Alice');
        $client = $this->queueEarly();
        $client->finalizedHead = 10;
        (new BlockScanner)->scan($client);
        DB::table('ethereum_early_state')->update(['paused_at' => now(), 'reorg_pending' => true]);
        $this->fakeSend();

        app(OutboxWorker::class)->runOnce();

        Http::assertSentCount(1);
        $this->assertStringContainsString('Status: Finalized', Http::recorded()->sole()[0]['text']);
        $this->assertDatabaseHas('notification_outbox', ['status' => 'sent']);
    }

    public function test_disabling_early_mode_defers_pending_observation_until_canonical_promotion(): void
    {
        $this->member('91001', 'Alice');
        $client = $this->queueEarly();
        config(['ethereum.early_enabled' => false]);
        $this->fakeSend();

        app(OutboxWorker::class)->runOnce();

        Http::assertNothingSent();
        $this->assertDatabaseHas('notification_outbox', ['notification_kind' => 'transfer', 'status' => 'retry', 'attempts' => 0]);
        $client->finalizedHead = 10;
        (new BlockScanner)->scan($client);
        $this->travel(6)->seconds();
        app(OutboxWorker::class)->runOnce();
        Http::assertSentCount(1);
        $this->assertStringContainsString('Status: Finalized', Http::recorded()->sole()[0]['text']);
        $this->assertDatabaseCount('notification_outbox', 1);
    }

    public function test_revoked_access_cancels_pending_early_delivery_without_http(): void
    {
        $member = $this->member('91001', 'Alice');
        $this->queueEarly();
        app(AccessService::class)->revokeGrant($member['grant']);

        app(OutboxWorker::class)->runOnce();

        Http::assertNothingSent();
        $this->assertDatabaseHas('notification_outbox', ['status' => 'cancelled']);
        $this->assertDatabaseCount('outbox_authorizations', 0);
    }

    public function test_paused_user_does_not_receive_early_data_and_other_users_label_stays_private(): void
    {
        $alice = $this->member('91001', 'Alice private label');
        $bob = $this->member('92002', 'Bob private label');
        $this->queueEarly();
        DB::table('user_chat_destinations')->where('user_id', $alice['user'])->update(['enabled' => false]);
        $this->fakeSend();

        app(OutboxWorker::class)->runOnce();
        $this->travel(1)->seconds();
        app(OutboxWorker::class)->runOnce();

        Http::assertSentCount(1);
        $request = Http::recorded()->sole()[0];
        $this->assertSame('92002', (string) $request['chat_id']);
        $this->assertStringContainsString('Bob private label', $request['text']);
        $this->assertStringNotContainsString('Alice private label', $request['text']);
        $this->assertDatabaseHas('notification_outbox', ['destination_id' => $alice['destination'], 'status' => 'cancelled']);
        $this->assertDatabaseHas('notification_outbox', ['destination_id' => $bob['destination'], 'status' => 'sent']);
    }

    public function test_receipt_failure_cannot_advance_early_cursor_or_enqueue_partial_work(): void
    {
        $this->member('91001', 'Alice');
        $client = $this->client();
        $this->prime($client);
        $client->latestHead = 16;
        $client->receiptFailure = true;

        try {
            app(EthereumEarlyScanner::class)->scan($client);
            $this->fail('An unavailable receipt must block early coverage.');
        } catch (RpcException) {
            $this->assertSame(9, (int) DB::table('ethereum_early_state')->sole()->last_block);
            $this->assertDatabaseCount('ethereum_early_events', 0);
            $this->assertDatabaseCount('notification_outbox', 0);
            $this->assertDatabaseCount('transfers', 0);
        }
    }

    public function test_watchset_change_during_receipt_collection_prevents_stale_snapshot_commit(): void
    {
        $this->member('91001', 'Alice');
        $client = $this->client();
        $this->prime($client);
        $client->latestHead = 16;
        $client->duringReceipt = function (): void {
            $set = DB::table('watch_sets')->insertGetId(['chain' => 'ethereum', 'version' => 2, 'cutover_block' => -1, 'activated_at' => now()]);
            DB::table('watch_set_wallets')->insert(['watch_set_id' => $set, 'wallet_id' => DB::table('wallets')->value('id')]);
            DB::table('scan_cursors')->where('role', 'primary')->update(['watch_set_id' => $set]);
        };

        try {
            app(EthereumEarlyScanner::class)->scan($client);
            $this->fail('A watch-set race must reject the stale HTTP snapshot.');
        } catch (ScanException) {
            $this->assertSame(9, (int) DB::table('ethereum_early_state')->sole()->last_block);
            $this->assertDatabaseCount('ethereum_early_events', 0);
            $this->assertDatabaseCount('notification_outbox', 0);
        }
    }

    public function test_unwitnessed_reorg_does_not_cancel_or_retract_anything(): void
    {
        $this->member('91001', 'Alice');
        $client = $this->queueEarly();
        $client->reorganize(10);

        try {
            app(EthereumEarlyScanner::class)->scan($client);
            $this->fail('A changed boundary needs independent evidence.');
        } catch (ScanException) {
            $this->assertDatabaseHas('ethereum_early_events', ['state' => 'observed']);
            $this->assertDatabaseHas('notification_outbox', ['notification_kind' => 'transfer', 'status' => 'pending']);
            $this->assertSame(0, DB::table('notification_outbox')->where('notification_kind', 'reorg')->count());
            $this->assertDatabaseCount('transfers', 0);
        }
    }

    public function test_confirmed_reorg_cancels_never_delivered_message_without_false_correction(): void
    {
        $this->member('91001', 'Alice');
        $client = $this->queueEarly();
        $client->reorganize(10);

        app(EthereumEarlyScanner::class)->scan($client, 100, $this->witness($client));

        $this->assertDatabaseHas('ethereum_early_events', ['state' => 'orphaned']);
        $this->assertDatabaseHas('notification_outbox', ['notification_kind' => 'transfer', 'status' => 'cancelled']);
        $this->assertSame(0, DB::table('notification_outbox')->where('notification_kind', 'reorg')->count());
        $this->assertDatabaseCount('transfers', 0);
        app(OutboxWorker::class)->runOnce();
        Http::assertNothingSent();
    }

    public function test_provider_disagreement_defers_early_delivery_without_false_retraction(): void
    {
        $this->member('91001', 'Alice');
        $client = $this->queueEarly();
        $witness = $this->witness($client);
        $client->reorganize(10);

        try {
            app(EthereumEarlyScanner::class)->scan($client, 100, $witness);
            $this->fail('One inconsistent provider cannot prove a reorganization.');
        } catch (ScanException) {
            $this->assertDatabaseHas('ethereum_early_events', ['state' => 'observed']);
            $this->assertSame(0, DB::table('notification_outbox')->where('notification_kind', 'reorg')->count());
            $this->fakeSend();
            app(OutboxWorker::class)->runOnce();
            Http::assertNothingSent();
            $this->assertDatabaseHas('notification_outbox', ['notification_kind' => 'transfer', 'status' => 'retry', 'attempts' => 0]);
        }
    }

    public function test_reorganization_beyond_the_configured_window_pauses_only_early_coverage(): void
    {
        $this->member('91001', 'Alice');
        $client = $this->client();
        $this->prime($client);
        $client->latestHead = 21;
        app(EthereumEarlyScanner::class)->scan($client);
        config(['ethereum.reorg_window' => 4]);
        $client->reorganize(10);

        try {
            app(EthereumEarlyScanner::class)->scan($client, 100, $this->witness($client));
            $this->fail('A deep reorganization must not cause unbounded replay.');
        } catch (ScanException) {
            $this->assertNotNull(DB::table('ethereum_early_state')->sole()->paused_at);
            $this->assertNull(DB::table('scan_cursors')->sole()->paused_at);
            $this->assertDatabaseHas('ethereum_early_events', ['state' => 'observed']);
            $this->assertDatabaseCount('transfers', 0);
            $this->assertSame(0, DB::table('notification_outbox')->where('notification_kind', 'reorg')->count());
        }
    }

    public function test_reorg_correction_is_only_queued_for_the_original_actual_recipient(): void
    {
        $alice = $this->member('91001', 'Alice private label');
        $bob = $this->member('92002', 'Bob private label');
        $client = $this->queueEarly();
        $this->fakeSend();
        app(OutboxWorker::class)->runOnce();
        $this->assertDatabaseHas('notification_outbox', ['destination_id' => $alice['destination'], 'status' => 'sent']);
        $client->reorganize(10);

        app(EthereumEarlyScanner::class)->scan($client, 100, $this->witness($client));

        $correction = DB::table('notification_outbox')->where('notification_kind', 'reorg')->sole();
        $this->assertSame((int) $alice['destination'], (int) $correction->destination_id);
        $this->assertDatabaseHas('notification_outbox', ['destination_id' => $bob['destination'], 'notification_kind' => 'transfer', 'status' => 'cancelled']);
        $this->travel(2)->seconds();
        app(OutboxWorker::class)->runOnce();
        Http::assertSentCount(2);
        $request = Http::recorded()->last()[0];
        $this->assertSame('91001', (string) $request['chat_id']);
        $this->assertMatchesRegularExpression('/reorg|removed|orphan|invalidated|no longer canonical/i', $request['text']);
        $this->assertStringNotContainsString('Bob private label', $request['text']);
    }

    public function test_canonical_promotion_during_reorg_evidence_preserves_the_pending_finalized_notification(): void
    {
        $this->member('91001', 'Alice');
        $client = $this->queueEarly();
        $early = DB::table('ethereum_early_events')->sole();
        $outboxId = (int) DB::table('notification_outbox')->sole()->id;
        $primary = DB::table('scan_cursors')->sole();
        $earlyBoundary = DB::table('ethereum_early_state')->sole();
        $client->reorganize(10);
        $witness = $this->witness($client);
        $lookups = 0;
        $canonicalId = null;
        $witness->duringBlock = function (int $height) use ($witness, &$lookups, &$canonicalId, $early): void {
            if ($height !== 10 || ++$lookups !== 2) {
                return;
            }
            $witness->duringBlock = null;
            // Reconciliation can establish finality while a scanner is waiting
            // on external evidence, without moving the primary scan cursor.
            DB::transaction(function () use (&$canonicalId, $early): void {
                $payload = array_intersect_key((array) $early, array_flip([
                    'chain', 'transaction_hash', 'receipt_log_position', 'contract_address',
                    'from_address', 'to_address', 'amount_raw', 'block_number', 'block_hash', 'block_timestamp',
                ]));
                $canonicalId = DB::table('transfers')->insertGetId($payload + [
                    'finality' => 'finalized', 'created_at' => now(), 'updated_at' => now(),
                ]);
                app(OutboxRouter::class)->enqueue($canonicalId);
            });
        };

        $refused = false;
        try {
            app(EthereumEarlyScanner::class)->scan($client, 100, $witness);
        } catch (ScanException $exception) {
            $refused = true;
            $this->assertStringContainsString('Canonical finalization changed', $exception->getMessage());
        }

        $this->assertTrue($refused, 'Concurrent finalization must require fresh reorganization evidence.');
        $this->assertNotNull($canonicalId, 'Canonical promotion must occur after the observed reorg snapshot was read.');
        $this->assertDatabaseHas('ethereum_early_events', [
            'id' => $early->id, 'state' => 'finalized', 'canonical_transfer_id' => $canonicalId,
        ]);
        $this->assertDatabaseHas('notification_outbox', [
            'id' => $outboxId, 'transfer_id' => $canonicalId, 'notification_kind' => 'transfer', 'status' => 'pending',
        ]);
        $this->assertDatabaseCount('notification_outbox', 1);
        $this->assertSame(0, DB::table('notification_outbox')->where('notification_kind', 'reorg')->count());
        $this->assertSame($primary->last_block, DB::table('scan_cursors')->sole()->last_block);
        $this->assertSame($primary->last_block_hash, DB::table('scan_cursors')->sole()->last_block_hash);
        $this->assertSame($earlyBoundary->last_block, DB::table('ethereum_early_state')->sole()->last_block);
        $this->assertSame($earlyBoundary->last_block_hash, DB::table('ethereum_early_state')->sole()->last_block_hash);
        $this->assertTrue((bool) DB::table('ethereum_early_state')->sole()->reorg_pending);
        $this->fakeSend();
        app(OutboxWorker::class)->runOnce();
        Http::assertSentCount(1);
        $this->assertStringContainsString('Status: Finalized', Http::recorded()->sole()[0]['text']);
        $this->assertDatabaseHas('notification_outbox', ['id' => $outboxId, 'status' => 'sent']);
    }

    public function test_a_restored_canonical_event_is_delivered_if_its_early_message_was_cancelled_before_send(): void
    {
        $this->member('91001', 'Alice');
        $client = $this->queueEarly();
        $client->reorganize(10);
        app(EthereumEarlyScanner::class)->scan($client, 100, $this->witness($client));
        $this->assertDatabaseHas('notification_outbox', ['notification_kind' => 'transfer', 'status' => 'cancelled']);
        $this->assertSame(0, DB::table('notification_outbox')->where('notification_kind', 'reorg')->count());

        // The original branch later wins finality; the canonical scanner is the authority.
        $canonical = $this->client();
        $canonical->finalizedHead = 10;
        (new BlockScanner)->scan($canonical);
        $this->fakeSend();
        app(OutboxWorker::class)->runOnce();

        Http::assertSentCount(1);
        $this->assertStringContainsString('Status: Finalized', Http::recorded()->sole()[0]['text']);
        $this->assertDatabaseCount('transfers', 1);
        $this->assertDatabaseHas('notification_outbox', ['transfer_id' => DB::table('transfers')->sole()->id, 'status' => 'sent']);
    }

    public function test_a_restored_event_gets_finalized_notice_after_an_actual_retraction(): void
    {
        $this->member('91001', 'Alice');
        $client = $this->queueEarly();
        $this->fakeSend();
        app(OutboxWorker::class)->runOnce();
        $client->reorganize(10);
        app(EthereumEarlyScanner::class)->scan($client, 100, $this->witness($client));
        $this->travel(2)->seconds();
        app(OutboxWorker::class)->runOnce();
        Http::assertSentCount(2);

        $canonical = $this->client();
        $canonical->finalizedHead = 10;
        (new BlockScanner)->scan($canonical);
        $this->travel(2)->seconds();
        app(OutboxWorker::class)->runOnce();

        Http::assertSentCount(3);
        $this->assertStringContainsString('Status: Finalized', Http::recorded()->last()[0]['text']);
        $this->assertDatabaseCount('transfers', 1);
    }

    public function test_a_retracted_version_waits_for_finality_without_blocking_other_new_early_events(): void
    {
        $this->member('91001', 'Alice');
        $client = $this->queueEarly();
        $client->reorganize(10);
        app(EthereumEarlyScanner::class)->scan($client, 100, $this->witness($client));
        $original = $this->client();
        $original->latestHead = 17;
        $original->addEvent(11, EarlyChainClient::address(2), EarlyChainClient::address(1), seed: 'new-after-retraction');

        app(EthereumEarlyScanner::class)->scan($original, 100, $this->witness($original));

        $this->assertSame(11, (int) DB::table('ethereum_early_state')->sole()->last_block);
        $this->assertDatabaseHas('ethereum_early_events', ['block_number' => 10, 'state' => 'orphaned']);
        $this->assertDatabaseHas('ethereum_early_events', ['block_number' => 11, 'state' => 'observed']);
        $this->assertSame(1, DB::table('notification_outbox')->where('status', 'pending')->count());
        $this->assertDatabaseCount('transfers', 0);
        $this->assertSame(-1, (int) DB::table('scan_cursors')->sole()->last_block);
    }

    public function test_restored_finality_deduplicates_each_destination_independently(): void
    {
        $this->member('91001', 'Alice');
        $this->member('92002', 'Bob');
        $client = $this->queueEarly();
        $this->fakeSend();
        app(OutboxWorker::class)->runOnce();
        $this->travel(2)->seconds();
        app(OutboxWorker::class)->runOnce();
        Http::assertSentCount(2);
        $client->reorganize(10);
        app(EthereumEarlyScanner::class)->scan($client, 100, $this->witness($client));
        $this->travel(2)->seconds();
        app(OutboxWorker::class)->runOnce();
        Http::assertSentCount(3);
        $this->assertSame('91001', (string) Http::recorded()->last()[0]['chat_id']);

        $canonical = $this->client();
        $canonical->finalizedHead = 10;
        (new BlockScanner)->scan($canonical);
        $this->travel(2)->seconds();
        app(OutboxWorker::class)->runOnce();
        app(OutboxWorker::class)->runOnce();

        Http::assertSentCount(4);
        $requests = Http::recorded()->map(fn ($pair) => $pair[0]);
        $this->assertSame(3, $requests->filter(fn ($request) => (string) $request['chat_id'] === '91001')->count());
        $this->assertSame(1, $requests->filter(fn ($request) => (string) $request['chat_id'] === '92002')->count());
        $this->assertStringContainsString('Status: Finalized', $requests->last()['text']);
    }

    public function test_finalization_during_correction_http_gets_a_finalized_followup_after_the_correction(): void
    {
        $this->exerciseFinalityDuringCorrection(false);
    }

    public function test_finalization_delivered_before_correction_http_returns_gets_one_final_followup(): void
    {
        $this->exerciseFinalityDuringCorrection(true);
    }

    public function test_new_wallet_default_starts_after_the_current_six_block_head(): void
    {
        config(['blockchain.chains.ethereum.providers.primary.rpc_url' => 'https://early-provider.invalid/rpc']);
        $access = app(AccessService::class);
        $user = $access->activate($access->issue()->token, '93003')->userId;
        Http::fake(function ($request) {
            $method = $request['method'];
            $result = match ($method) {
                'eth_chainId' => '0x1',
                'eth_getBlockByNumber' => [
                    'number' => $request['params'][0] === 'latest' ? '0x64' : $request['params'][0],
                    'hash' => '0x'.str_repeat('c', 64), 'timestamp' => '0x6ae03400',
                ],
                default => throw new RuntimeException('Unexpected synthetic RPC method.'),
            };

            return Http::response(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => $result]);
        });

        $wallet = app(WalletService::class)->add($user, 'ethereum', EarlyChainClient::address(3));

        $this->assertSame(95, (int) $wallet->start_block);
        $this->assertSame(95, (int) DB::table('wallet_subscriptions')->sole()->notification_start_block);
        $this->assertSame(94, (int) DB::table('scan_cursors')->sole()->last_block);
        Http::assertSent(fn ($request) => $request['method'] === 'eth_getBlockByNumber' && $request['params'][0] === 'latest');
        Http::assertNotSent(fn ($request) => $request['method'] === 'eth_getBlockByNumber' && $request['params'][0] === 'finalized');
    }

    public function test_an_in_flight_success_is_corrected_only_after_the_send_result_is_known(): void
    {
        $this->member('91001', 'Alice');
        $client = $this->queueEarly();
        $changed = false;
        Http::fake(function ($request) use ($client, &$changed) {
            if (! $changed) {
                $changed = true;
                $client->reorganize(10);
                app(EthereumEarlyScanner::class)->scan($client, 100, $this->witness($client));
                $this->assertSame(0, DB::table('notification_outbox')->where('notification_kind', 'reorg')->count());
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 42, 'chat' => ['id' => (int) $request['chat_id']]]]);
        });

        app(OutboxWorker::class)->runOnce();

        Http::assertSentCount(1);
        $this->assertDatabaseHas('notification_outbox', ['notification_kind' => 'transfer', 'status' => 'sent']);
        $this->assertSame(1, DB::table('notification_outbox')->where('notification_kind', 'reorg')->count());
        $this->travel(2)->seconds();
        app(OutboxWorker::class)->runOnce();
        Http::assertSentCount(2);
        $this->assertMatchesRegularExpression('/reorg|removed|orphan|invalidated|no longer canonical/i', Http::recorded()->last()[0]['text']);
    }

    public function test_a_definitively_rejected_in_flight_send_does_not_create_a_false_correction(): void
    {
        $this->member('91001', 'Alice');
        $client = $this->queueEarly();
        Http::fake(function () use ($client) {
            $client->reorganize(10);
            app(EthereumEarlyScanner::class)->scan($client, 100, $this->witness($client));

            return Http::response(['ok' => false, 'error_code' => 400], 400);
        });

        app(OutboxWorker::class)->runOnce();

        Http::assertSentCount(1);
        $this->assertDatabaseHas('notification_outbox', ['notification_kind' => 'transfer', 'status' => 'cancelled']);
        $this->assertDatabaseHas('delivery_attempts', ['outcome' => 'failed', 'error_code' => '400']);
        $this->assertSame(0, DB::table('notification_outbox')->where('notification_kind', 'reorg')->count());
        $this->assertFalse(app(OutboxWorker::class)->runOnce());
        Http::assertSentCount(1);
    }

    public function test_fresh_reactivation_uses_the_durable_early_boundary_without_rpc_in_the_transaction(): void
    {
        $member = $this->member('91001', 'Alice');
        $this->queueEarly();
        $access = app(AccessService::class);
        $access->revokeGrant($member['grant']);
        $reactivated = $access->activate($access->issue()->token, '91001');

        app(WalletService::class)->refreshWatchSetsForUser($reactivated->userId, true);

        $this->assertSame($member['user'], $reactivated->userId);
        $this->assertSame(11, (int) DB::table('wallet_subscriptions')->sole()->notification_start_block);
        $this->assertSame(0, (int) DB::table('user_wallets')->sole()->start_block);
        $this->assertSame(-1, (int) DB::table('scan_cursors')->sole()->last_block);
        $this->assertSame(10, (int) DB::table('ethereum_early_state')->sole()->last_block);
        $this->assertDatabaseHas('notification_outbox', ['notification_kind' => 'transfer', 'status' => 'cancelled']);
        Http::assertNothingSent();
    }

    public function test_migration_upgrade_and_rollback_preserve_existing_finalized_outbox_authorizations_and_attempts(): void
    {
        $directory = sys_get_temp_dir().'/coin-bot-early-schema-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $database = $directory.'/database.sqlite';
        touch($database);
        $code = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default'=>'sqlite','database.connections.sqlite.database'=>getenv('DB_DATABASE'),
    'database.connections.sqlite.url'=>null,'ethereum.early_enabled'=>false]);
Illuminate\Support\Facades\DB::purge('sqlite');
Illuminate\Support\Facades\Http::preventStrayRequests();
if (Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true,'--no-interaction'=>true])!==0) {
    throw new RuntimeException('Cannot initialize synthetic migration fixture.');
}
$migration=require database_path('migrations/2026_10_27_000100_add_ethereum_early_monitoring.php');
$migration->down();
$access=app(App\Access\AccessService::class);
$members=[];
foreach (['94004','95005'] as $actor) {
    $activation=$access->activate($access->issue()->token,$actor);
    $wallet=app(App\Wallets\WalletService::class)->add($activation->userId,'ethereum','0x'.str_repeat('1',40),'Legacy '.$actor,0);
    $subscription=Illuminate\Support\Facades\DB::table('wallet_subscriptions')->where('user_wallet_id',$wallet->id)->first();
    $members[]=[$activation,$subscription];
}
$transfer=Illuminate\Support\Facades\DB::table('transfers')->insertGetId([
    'chain'=>'ethereum','transaction_hash'=>'0x'.str_repeat('a',64),'receipt_log_position'=>1,
    'contract_address'=>App\Blockchain\EthereumRpcClient::USDT_CONTRACT,
    'from_address'=>'0x'.str_repeat('2',40),'to_address'=>'0x'.str_repeat('1',40),
    'amount_raw'=>'123456789012345678901234567890','block_number'=>10,'block_hash'=>'0x'.str_repeat('b',64),
    'block_timestamp'=>1793030520,'finality'=>'finalized','created_at'=>now(),'updated_at'=>now(),
]);
foreach ($members as [$activation,$subscription]) {
    $outbox=Illuminate\Support\Facades\DB::table('notification_outbox')->insertGetId([
        'transfer_id'=>$transfer,'destination_id'=>$subscription->destination_id,'notification_kind'=>'transfer',
        'status'=>'retry','attempts'=>2,'available_at'=>now()->addSeconds(30),'last_error'=>'Synthetic retry.',
        'created_at'=>now(),'updated_at'=>now(),
    ]);
    Illuminate\Support\Facades\DB::table('outbox_authorizations')->insert([
        'outbox_id'=>$outbox,'user_id'=>$activation->userId,'subscription_id'=>$subscription->id,
        'grant_id'=>$activation->grantId,'historical_override'=>false,'created_at'=>now(),'updated_at'=>now(),
    ]);
    Illuminate\Support\Facades\DB::table('delivery_attempts')->insert([
        'outbox_id'=>$outbox,'outcome'=>'retry','error_code'=>'429','attempted_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
    ]);
}
function legacySnapshot(): array {
    $snapshot=[];
    foreach (['transfers','notification_outbox','outbox_authorizations','delivery_attempts'] as $table) {
        $snapshot[$table]=Illuminate\Support\Facades\DB::table($table)->orderBy('id')->get()->map(function($row)use($table){
            $values=(array)$row;
            if($table==='notification_outbox'){unset($values['early_event_id']);}
            ksort($values);
            return $values;
        })->all();
    }
    return $snapshot;
}
function legacyForeignKeys(): array {
    $links=[];
    foreach (['notification_outbox','outbox_authorizations','delivery_attempts'] as $table) {
        $links[$table]=[];
        foreach (Illuminate\Support\Facades\DB::select('PRAGMA foreign_key_list('.$table.')') as $row) {
            $values=(array)$row;
            if ($table==='notification_outbox' && $values['from']==='early_event_id') {continue;}
            unset($values['id']);
            ksort($values);
            $links[$table][]=json_encode($values,JSON_THROW_ON_ERROR);
        }
        sort($links[$table]);
    }
    return $links;
}
$before=legacySnapshot();
$beforeLinks=legacyForeignKeys();
$migration->up();
if (!Illuminate\Support\Facades\Schema::hasColumn('notification_outbox','early_event_id')
    || legacySnapshot()!==$before || legacyForeignKeys()!==$beforeLinks
    || Illuminate\Support\Facades\DB::select('PRAGMA foreign_key_check')!==[]
    || (int) Illuminate\Support\Facades\DB::selectOne('PRAGMA foreign_keys')->foreign_keys!==1) {
    throw new RuntimeException('Existing legacy records or foreign keys changed during upgrade.');
}
$migration->down();
if (Illuminate\Support\Facades\Schema::hasColumn('notification_outbox','early_event_id')
    || legacySnapshot()!==$before || legacyForeignKeys()!==$beforeLinks
    || Illuminate\Support\Facades\DB::select('PRAGMA foreign_key_check')!==[]
    || (int) Illuminate\Support\Facades\DB::selectOne('PRAGMA foreign_keys')->foreign_keys!==1) {
    throw new RuntimeException('Existing legacy records or foreign keys changed during rollback.');
}
echo "Live SQLite migration preserved two users, exact amounts, outboxes, authorizations and attempts.\n";
PHP;
        try {
            $process = new Process([PHP_BINARY, '-r', $code], base_path(), [
                'APP_ENV' => 'testing', 'APP_DEBUG' => 'false',
                'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
                'APP_CONFIG_CACHE' => $directory.'/config.php',
                'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '',
                'CACHE_STORE' => 'array', 'LOG_CHANNEL' => 'null',
            ]);
            $process->setTimeout(30);
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
            $this->assertStringContainsString('Live SQLite migration preserved two users', $process->getOutput());
        } finally {
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function test_rollback_with_early_history_refuses_without_erasing_any_delivery_state(): void
    {
        $this->member('91001', 'Alice');
        $this->queueEarly();
        $before = [DB::table('ethereum_early_events')->count(), DB::table('notification_outbox')->count(), DB::table('outbox_authorizations')->count()];
        $migration = require database_path('migrations/2026_10_27_000100_add_ethereum_early_monitoring.php');
        $refused = false;
        try {
            $migration->down();
        } catch (RuntimeException) {
            $refused = true;
        }
        $this->assertTrue($refused, 'Rollback must refuse to discard preliminary history.');
        $this->assertTrue(Schema::hasTable('ethereum_early_events'));
        $this->assertSame($before, [DB::table('ethereum_early_events')->count(), DB::table('notification_outbox')->count(), DB::table('outbox_authorizations')->count()]);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    private function member(string $actor, string $label): array
    {
        $access = app(AccessService::class);
        $activation = $access->activate($access->issue()->token, $actor);
        $wallet = app(WalletService::class)->add($activation->userId, 'ethereum', EarlyChainClient::address(1), $label, 0);

        return ['user' => $activation->userId, 'grant' => $activation->grantId, 'wallet' => $wallet->id,
            'destination' => DB::table('wallet_subscriptions')->where('user_wallet_id', $wallet->id)->value('destination_id')];
    }

    private function client(): EarlyChainClient
    {
        $client = new EarlyChainClient;
        $client->addEvent(10, EarlyChainClient::address(2), EarlyChainClient::address(1));

        return $client;
    }

    private function prime(EarlyChainClient $client): void
    {
        $this->assertTrue(app(EthereumEarlyScanner::class)->scan($client)->idle);
    }

    private function queueEarly(): EarlyChainClient
    {
        $client = $this->client();
        $this->prime($client);
        $client->latestHead = 16;
        app(EthereumEarlyScanner::class)->scan($client);

        return $client;
    }

    private function witness(EarlyChainClient $client): EarlyChainClient
    {
        $witness = clone $client;
        $witness->providerName = 'secondary';

        return $witness;
    }

    private function fakeSend(): void
    {
        Http::fake(fn ($request) => Http::response(['ok' => true,
            'result' => ['message_id' => 42, 'chat' => ['id' => (int) $request['chat_id']]]]));
    }

    private function exerciseFinalityDuringCorrection(bool $deliverInsideCallback): void
    {
        $this->member('91001', 'Alice');
        $client = $this->queueEarly();
        $requests = 0;
        Http::fake(function ($request) use (&$requests, $deliverInsideCallback) {
            $requests++;
            if ($requests === 2) {
                $canonical = $this->client();
                $canonical->finalizedHead = 10;
                (new BlockScanner)->scan($canonical);
                if ($deliverInsideCallback) {
                    $this->travel(2)->seconds();
                    app(OutboxWorker::class)->runOnce();
                    $this->assertSame(3, $requests, 'The canonical notification must actually dispatch before the correction response returns.');
                }
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => $requests + 40, 'chat' => ['id' => (int) $request['chat_id']]]]);
        });
        app(OutboxWorker::class)->runOnce();
        $client->reorganize(10);
        app(EthereumEarlyScanner::class)->scan($client, 100, $this->witness($client));
        $this->travel(2)->seconds();

        app(OutboxWorker::class)->runOnce();
        $this->travel(2)->seconds();
        app(OutboxWorker::class)->runOnce();

        Http::assertSentCount($deliverInsideCallback ? 4 : 3);
        $this->assertStringContainsString('Status: Finalized', Http::recorded()->last()[0]['text']);
        $this->assertDatabaseCount('transfers', 1);
        $this->assertSame(0, DB::table('notification_outbox')->whereIn('status', ['pending', 'retry', 'processing'])->count());
    }
}
