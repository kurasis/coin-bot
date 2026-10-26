<?php

namespace Tests\Feature;

use App\Access\AccessService;
use App\Blockchain\AddressCodec;
use App\Blockchain\Contracts\ChainClient;
use App\Blockchain\TronRpcClient;
use App\Monitoring\OutboxRouter;
use App\Monitoring\ScanException;
use App\Operations\MaintenanceGuard;
use App\Reconciliation\Reconciler;
use App\Telegram\DispatchRateGate;
use App\Telegram\OutboxWorker;
use App\Telegram\QueueLeases;
use App\Telegram\ReplyWorker;
use App\Telegram\TelegramClient;
use App\Telegram\TelegramException;
use App\Telegram\TelegramPoller;
use App\Wallets\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = '123456:'.str_repeat('fixture_', 5);
        config(['telegram.token' => $this->token, 'telegram.poll_timeout' => 0]);
        Http::preventStrayRequests();
        $this->freezeTime();
    }

    public function test_private_activation_persists_destination_reply_and_offset_without_storing_key(): void
    {
        $key = app(AccessService::class)->issue();
        Http::fake(['*/getUpdates' => Http::response(['ok' => true, 'result' => [$this->update(501, '1001', '/start '.$key->token)]])]);
        $this->assertSame(1, app(TelegramPoller::class)->runOnce());
        $this->assertDatabaseHas('users', ['telegram_user_id' => '1001']);
        $this->assertDatabaseHas('chat_destinations', ['chat_id' => '1001', 'kind' => 'private']);
        $this->assertDatabaseHas('telegram_update_offsets', ['worker' => 'main', 'next_update_id' => 502]);
        $this->assertDatabaseCount('access_grants', 1);
        $reply = DB::table('telegram_replies')->first();
        $this->assertSame('pending', $reply->status);
        $this->assertStringNotContainsString($key->token, json_encode(DB::table('telegram_updates')->get()));
        $this->assertStringNotContainsString($key->token, $reply->text);
        // Replay cannot consume the key twice or enqueue another reply.
        $this->assertSame(0, app(TelegramPoller::class)->runOnce());
        $this->assertDatabaseCount('access_grants', 1);
        $this->assertDatabaseCount('telegram_replies', 1);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/getUpdates') && $request['allowed_updates'] === ['message']);
    }

    public function test_group_and_mismatched_actor_cannot_consume_a_key_or_enroll_a_destination(): void
    {
        $key = app(AccessService::class)->issue();
        $group = $this->update(10, '1001', '/start '.$key->token);
        $group['message']['chat'] = ['id' => -10010, 'type' => 'group'];
        $mismatch = $this->update(11, '1001', '/start '.$key->token);
        $mismatch['message']['chat']['id'] = 2002;
        Http::fake(['*/getUpdates' => Http::response(['ok' => true, 'result' => [$group, $mismatch]])]);
        app(TelegramPoller::class)->runOnce();
        $this->assertDatabaseCount('access_grants', 0);
        $this->assertDatabaseCount('chat_destinations', 0);
        $this->assertDatabaseCount('telegram_replies', 0);
        $this->assertNull(DB::table('access_keys')->value('redeemed_at'));
    }

    public function test_unknown_user_gets_only_a_neutral_prompt_and_no_admin_privileges(): void
    {
        Http::fake(['*/getUpdates' => Http::response(['ok' => true, 'result' => [$this->update(12, '9999', '/access:issue')]])]);
        app(TelegramPoller::class)->runOnce();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('access_keys', 0);
        $this->assertSame('Activate access with an individual key: /start <key>.', DB::table('telegram_replies')->value('text'));
    }

    public function test_invalid_activation_has_generic_response_and_does_not_persist_message_body(): void
    {
        $input = str_repeat('x', 43);
        Http::fake(['*/getUpdates' => Http::response(['ok' => true, 'result' => [$this->update(15, '1001', '/start '.$input)]])]);
        app(TelegramPoller::class)->runOnce();
        $this->assertDatabaseHas('telegram_updates', ['result' => 'denied']);
        $this->assertStringNotContainsString($input, DB::table('telegram_replies')->value('text'));
        $this->assertDatabaseCount('access_grants', 0);
    }

    public function test_stop_and_resume_only_change_that_users_private_permission(): void
    {
        $alice = $this->user('1001', 'Alice');
        $bob = $this->user('2002', 'Bob');
        Http::fakeSequence()->push(['ok' => true, 'result' => [$this->update(20, '1001', '/stop')]])
            ->push(['ok' => true, 'result' => [$this->update(21, '1001', '/resume')]]);
        app(TelegramPoller::class)->runOnce();
        $this->assertDatabaseHas('user_chat_destinations', ['user_id' => $alice['user'], 'enabled' => 0]);
        $this->assertDatabaseHas('user_chat_destinations', ['user_id' => $bob['user'], 'enabled' => 1]);
        app(TelegramPoller::class)->runOnce();
        $this->assertDatabaseHas('user_chat_destinations', ['user_id' => $alice['user'], 'enabled' => 1]);
    }

    public function test_transfer_delivery_is_private_for_two_users_following_the_same_wallet(): void
    {
        $this->user('1001', 'Alice private label');
        $this->user('2002', 'Bob private label');
        $transfer = $this->transfer();
        app(OutboxRouter::class)->enqueue($transfer);
        $this->fakeSendSuccess();
        $baseline = DB::transactionLevel();
        $this->assertTrue(app(OutboxWorker::class)->runOnce());
        $this->travel(2)->seconds();
        $this->assertTrue(app(OutboxWorker::class)->runOnce());
        $this->assertDatabaseCount('notification_outbox', 2);
        $this->assertSame(2, DB::table('notification_outbox')->where('status', 'sent')->count());
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request['chat_id'] === '1001'
            && str_contains($request['text'], 'Alice private label') && ! str_contains($request['text'], 'Bob private label')
            && str_contains($request['text'], '1.000001 USDT') && str_contains($request['text'], 'Event: '));
        Http::assertSent(fn ($request) => $request['chat_id'] === '2002'
            && str_contains($request['text'], 'Bob private label') && ! str_contains($request['text'], 'Alice private label'));
        $this->assertSame($baseline, DB::transactionLevel());
    }

    public function test_lease_excludes_other_claims_and_expired_lease_rejects_old_worker(): void
    {
        $this->queued();
        $leases = app(QueueLeases::class);
        $first = $leases->claim('notification_outbox');
        $this->assertNotNull($first);
        $this->assertNull($leases->claim('notification_outbox'));
        $this->travel(3)->minutes();
        $this->assertFalse($leases->beginAttempt('notification_outbox', $first), 'An expired unreclaimed token must not start HTTP.');
        $this->assertSame(0, (int) DB::table('notification_outbox')->value('attempts'));
        $second = $leases->claim('notification_outbox');
        $this->assertSame($first->id, $second->id);
        $this->assertNotSame($first->lease_token, $second->lease_token);
        $this->assertFalse($leases->beginAttempt('notification_outbox', $first));
        $this->assertTrue($leases->beginAttempt('notification_outbox', $second));
    }

    public function test_rate_limits_are_shared_between_replies_and_transfer_workers(): void
    {
        $gate = app(DispatchRateGate::class);
        $this->assertSame(0, $gate->reserve('1001'));
        $this->assertGreaterThanOrEqual(1000, $gate->reserve('1001'));
        $this->assertGreaterThan(0, $gate->reserve('2002'));
        $this->travel(1)->seconds();
        $this->assertSame(0, $gate->reserve('2002'));
    }

    public function test_429_honors_retry_after_and_preserves_pending_delivery(): void
    {
        $row = $this->queued();
        Http::fake(['*' => Http::response(['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 30]], 429)]);
        app(OutboxWorker::class)->runOnce();
        $updated = DB::table('notification_outbox')->find($row->id);
        $this->assertSame('retry', $updated->status);
        $this->assertSame(1, (int) $updated->attempts);
        $this->assertSame(now()->addSeconds(30)->format('Y-m-d H:i:s'), $updated->available_at);
        $this->assertNull($updated->lease_token);
        $this->assertGreaterThanOrEqual(30000, app(DispatchRateGate::class)->reserve('2002'));
        $this->assertFalse(app(OutboxWorker::class)->runOnce());
        Http::assertSentCount(1);
    }

    public function test_forbidden_chat_is_suspended_without_blocking_other_users(): void
    {
        $row = $this->queued();
        Http::fakeSequence()->push(['ok' => false, 'error_code' => 403], 403)
            ->push(['ok' => true, 'result' => ['message_id' => 42, 'chat' => ['id' => 2002]]]);
        app(OutboxWorker::class)->runOnce();
        $this->assertDatabaseHas('notification_outbox', ['id' => $row->id, 'status' => 'failed']);
        $this->assertNotNull(DB::table('chat_destinations')->where('id', $row->destination_id)->value('suspended_at'));
        $this->user('2002', 'Bob');
        app(OutboxRouter::class)->enqueue((int) $row->transfer_id);
        $this->travel(2)->seconds();
        app(OutboxWorker::class)->runOnce();
        $this->assertSame(1, DB::table('notification_outbox')->where('status', 'sent')->count());
    }

    public function test_ambiguous_network_timeout_is_retried_with_auditable_outcome(): void
    {
        $row = $this->queued();
        Http::fake(fn () => throw new ConnectionException('secret URL must not survive'));
        app(OutboxWorker::class)->runOnce();
        $this->assertDatabaseHas('notification_outbox', ['id' => $row->id, 'status' => 'retry', 'attempts' => 1]);
        $this->assertDatabaseHas('delivery_attempts', ['outbox_id' => $row->id, 'outcome' => 'ambiguous']);
        $this->assertStringNotContainsString('secret URL', DB::table('notification_outbox')->value('last_error'));
    }

    public function test_revoked_grant_and_changed_subscription_filter_prevent_sending(): void
    {
        $row = $this->queued();
        DB::table('wallet_subscriptions')->update(['min_amount' => '9999999999999']);
        $this->assertTrue(app(OutboxWorker::class)->runOnce());
        $this->assertDatabaseHas('notification_outbox', ['id' => $row->id, 'status' => 'cancelled']);
        Http::assertNothingSent();
        $fixture = $this->user('2002', 'Bob');
        DB::table('wallet_subscriptions')->where('user_id', $fixture['user'])->update(['min_amount' => '0']);
        app(OutboxRouter::class)->enqueue((int) $row->transfer_id);
        app(AccessService::class)->revokeGrant($fixture['grant']);
        app(OutboxWorker::class)->runOnce();
        Http::assertNothingSent();
    }

    public function test_failed_delivery_can_be_replayed_only_while_authorized(): void
    {
        $row = $this->queued();
        DB::table('notification_outbox')->where('id', $row->id)->update(['status' => 'failed', 'attempts' => 12]);
        app(OutboxWorker::class)->replay((int) $row->id);
        $this->assertDatabaseHas('notification_outbox', ['id' => $row->id, 'status' => 'pending', 'attempts' => 0]);
        $this->expectException(\LogicException::class);
        app(OutboxWorker::class)->replay((int) $row->id);
    }

    public function test_telegram_client_redacts_secret_bearing_upstream_errors_and_rejects_bad_success(): void
    {
        Http::fakeSequence()->push(['ok' => false, 'error_code' => 401, 'description' => $this->token], 401)
            ->push(['ok' => true, 'result' => ['message_id' => 3, 'chat' => ['id' => 9999]]]);
        try {
            app(TelegramClient::class)->sendMessage('1001', 'Test');
            $this->fail('Expected sanitized Telegram error.');
        } catch (TelegramException $exception) {
            $this->assertSame(401, $exception->status);
            $this->assertStringNotContainsString($this->token, (string) $exception);
        }
        $this->expectException(TelegramException::class);
        app(TelegramClient::class)->sendMessage('1001', 'Test');
    }

    public function test_enrollment_reply_is_durable_independently_of_activation(): void
    {
        $key = app(AccessService::class)->issue();
        Http::fake(['*/getUpdates' => Http::response(['ok' => true, 'result' => [$this->update(80, '1001', '/start '.$key->token)]]),
            '*/sendMessage' => Http::sequence()->push(['ok' => false, 'error_code' => 500], 500)
                ->push(['ok' => true, 'result' => ['message_id' => 42, 'chat' => ['id' => 1001]]])]);
        app(TelegramPoller::class)->runOnce();
        app(ReplyWorker::class)->runOnce();
        $this->assertDatabaseCount('access_grants', 1);
        $this->assertDatabaseHas('telegram_replies', ['update_id' => 80, 'status' => 'retry']);
        $this->assertDatabaseHas('telegram_update_offsets', ['next_update_id' => 81]);
        $this->travel(20)->seconds();
        app(ReplyWorker::class)->runOnce();
        $this->assertDatabaseHas('telegram_replies', ['update_id' => 80, 'status' => 'sent']);
        $this->assertDatabaseCount('access_grants', 1);
    }

    public function test_maintenance_pauses_all_telegram_http_without_discarding_pending_work(): void
    {
        if (! class_exists(MaintenanceGuard::class)) {
            $this->markTestSkipped('P11 maintenance integration is not installed yet.');
        }
        $row = $this->queued();
        $directory = storage_path('framework/testing-telegram-'.bin2hex(random_bytes(5)));
        config(['operations.state_directory' => $directory]);
        $guard = app(MaintenanceGuard::class);
        $guard->pause('Fixture maintenance.');
        try {
            $this->assertFalse(app(OutboxWorker::class)->runOnce());
            $this->assertFalse(app(ReplyWorker::class)->runOnce());
            $this->assertSame(0, app(TelegramPoller::class)->runOnce());
            $this->assertDatabaseHas('notification_outbox', ['id' => $row->id, 'status' => 'pending', 'attempts' => 0]);
            Http::assertNothingSent();
        } finally {
            $guard->resume();
            rmdir($directory);
        }
    }

    public function test_revocation_during_an_inflight_send_preserves_the_actual_delivery_evidence(): void
    {
        $row = $this->queued();
        $grant = (int) DB::table('access_grants')->value('id');
        Http::fake(function () use ($grant) {
            app(AccessService::class)->revokeGrant($grant);

            return Http::response(['ok' => true, 'result' => ['message_id' => 42, 'chat' => ['id' => 1001]]]);
        });
        app(OutboxWorker::class)->runOnce();
        $this->assertDatabaseHas('notification_outbox', ['id' => $row->id, 'status' => 'cancelled', 'telegram_message_id' => '42']);
        $this->assertDatabaseHas('delivery_attempts', ['outbox_id' => $row->id, 'outcome' => 'sent_after_cancel']);
    }

    public function test_a_revoked_grant_cannot_receive_a_stale_activation_confirmation(): void
    {
        $key = app(AccessService::class)->issue();
        Http::fake(['*/getUpdates' => Http::response(['ok' => true, 'result' => [$this->update(90, '1001', '/start '.$key->token)]]),
            '*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 42, 'chat' => ['id' => 1001]]])]);
        app(TelegramPoller::class)->runOnce();
        app(AccessService::class)->revokeGrant((int) DB::table('access_grants')->value('id'));
        app(ReplyWorker::class)->runOnce();
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage') && str_starts_with($request['text'], 'Access inactive.'));
    }

    public function test_missing_credentials_and_malformed_update_payload_do_not_advance_offsets(): void
    {
        Http::fake(['*/getUpdates' => Http::response(['ok' => true, 'result' => [['update_id' => 'invalid']]])]);
        try {
            app(TelegramPoller::class)->runOnce();
            $this->fail('Expected malformed update rejection.');
        } catch (TelegramException) {
            $this->assertDatabaseCount('telegram_update_offsets', 0);
        }
        config(['telegram.token' => null]);
        $this->expectException(\LogicException::class);
        app(TelegramClient::class)->getUpdates(0);
    }

    public function test_redirects_are_rejected_without_forwarding_token_or_private_payload(): void
    {
        Http::fake([
            '*/sendMessage' => Http::response('', 302, ['Location' => 'https://unapproved.example/collect']),
            'https://unapproved.example/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);
        try {
            app(TelegramClient::class)->sendMessage('1001', 'Private notification');
            $this->fail('A redirect must not be followed.');
        } catch (TelegramException $exception) {
            $this->assertSame(302, $exception->status);
            $this->assertStringNotContainsString($this->token, (string) $exception);
        }
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'https://unapproved.example/'));
    }

    public function test_active_user_key_renewal_preserves_stop_and_notification_boundary(): void
    {
        $fixture = $this->user('1001', 'Alice');
        app(WalletService::class)->refreshWatchSetsForUser($fixture['user'], false);
        DB::table('scan_cursors')->where('chain', 'ethereum')->update(['last_block' => 100]);
        DB::table('wallet_subscriptions')->where('id', $fixture['subscription'])->update(['notification_start_block' => 37]);
        $key = app(AccessService::class)->issue();
        Http::fakeSequence()->push(['ok' => true, 'result' => [$this->update(120, '1001', '/stop')]])
            ->push(['ok' => true, 'result' => [$this->update(121, '1001', '/start '.$key->token)]]);
        app(TelegramPoller::class)->runOnce();
        $this->assertDatabaseHas('user_chat_destinations', ['user_id' => $fixture['user'], 'enabled' => 0]);
        app(TelegramPoller::class)->runOnce();
        $this->assertDatabaseCount('access_grants', 2);
        $this->assertDatabaseHas('telegram_updates', ['update_id' => 121, 'result' => 'activated']);
        $this->assertDatabaseHas('user_chat_destinations', ['user_id' => $fixture['user'], 'enabled' => 0]);
        $this->assertSame(37, (int) DB::table('wallet_subscriptions')->where('id', $fixture['subscription'])->value('notification_start_block'));
        $this->assertSame(100, (int) DB::table('scan_cursors')->where('chain', 'ethereum')->value('last_block'));
    }

    public function test_active_user_key_renewal_does_not_clear_existing_permission_revocation_or_chat_suspension(): void
    {
        $fixture = $this->user('1001', 'Alice');
        DB::table('user_chat_destinations')->where('user_id', $fixture['user'])->update(['enabled' => false, 'revoked_at' => now()]);
        DB::table('chat_destinations')->where('id', $fixture['destination'])->update(['suspended_at' => now()]);
        $key = app(AccessService::class)->issue();
        Http::fake(['*/getUpdates' => Http::response(['ok' => true, 'result' => [$this->update(122, '1001', '/start '.$key->token)]])]);
        app(TelegramPoller::class)->runOnce();
        $this->assertDatabaseCount('access_grants', 2);
        $this->assertDatabaseHas('user_chat_destinations', ['user_id' => $fixture['user'], 'enabled' => 0]);
        $this->assertNotNull(DB::table('user_chat_destinations')->where('user_id', $fixture['user'])->value('revoked_at'));
        $this->assertNotNull(DB::table('chat_destinations')->where('id', $fixture['destination'])->value('suspended_at'));
    }

    public function test_paused_chain_backlog_does_not_send_or_starve_another_chain_and_survives_unpause(): void
    {
        $ethereum = $this->queued();
        $bob = $this->user('2002', 'Bob TRON');
        $tronAddress = AddressCodec::normalize('tron', '0x'.str_repeat('1', 40));
        $wallet = DB::table('wallets')->insertGetId(['chain' => 'tron', 'address' => $tronAddress]);
        DB::table('user_wallets')->where('user_id', $bob['user'])->update(['wallet_id' => $wallet]);
        $event = (array) DB::table('transfers')->find($ethereum->transfer_id);
        unset($event['id']);
        for ($position = 1; $position <= 5; $position++) {
            $copy = DB::table('transfers')->insertGetId(array_replace($event, ['receipt_log_position' => $position]));
            app(OutboxRouter::class)->enqueue($copy);
        }
        $tronTransfer = DB::table('transfers')->insertGetId(array_replace($event, [
            'chain' => 'tron', 'contract_address' => AddressCodec::normalize('tron', TronRpcClient::USDT_CONTRACT),
            'from_address' => AddressCodec::normalize('tron', '0x'.str_repeat('2', 40)),
            'to_address' => $tronAddress, 'finality' => 'solidified',
        ]));
        app(OutboxRouter::class)->enqueue($tronTransfer);
        DB::table('scan_cursors')->insert(['chain' => 'ethereum', 'role' => 'primary', 'provider' => 'primary', 'paused_at' => now()]);
        $this->fakeSendSuccess();
        app(OutboxWorker::class)->runOnce();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['chat_id'] === '2002' && str_contains($request['text'], 'Network: TRON'));
        $this->assertDatabaseHas('notification_outbox', ['id' => $ethereum->id, 'status' => 'retry', 'attempts' => 0]);
        $this->assertSame(6, DB::table('notification_outbox')->where('status', 'retry')->count());
        $this->assertSame(1, DB::table('notification_outbox')->where('status', 'sent')->count());
        $this->assertDatabaseCount('outbox_authorizations', 7);
        // This represents operator unpause only after the conflicting evidence has been validated.
        DB::table('scan_cursors')->where('chain', 'ethereum')->update(['paused_at' => null, 'pause_reason' => null]);
        $this->travel(6)->seconds();
        app(OutboxWorker::class)->runOnce();
        $this->assertDatabaseHas('notification_outbox', ['id' => $ethereum->id, 'status' => 'sent', 'attempts' => 1]);
        Http::assertSentCount(2);
    }

    public function test_chain_pause_after_claim_is_rechecked_before_http_without_cancelling_the_event(): void
    {
        $row = $this->queued();
        DB::table('scan_cursors')->insert(['chain' => 'ethereum', 'role' => 'reconciliation', 'provider' => 'secondary']);
        $realAccess = app(AccessService::class);
        $checks = 0;
        $this->partialMock(AccessService::class, function ($mock) use ($realAccess, &$checks): void {
            $mock->shouldReceive('deliveryAuthorized')->twice()->andReturnUsing(function (int $id) use ($realAccess, &$checks): bool {
                if (++$checks === 2) {
                    DB::table('scan_cursors')->where('chain', 'ethereum')->update(['paused_at' => now()]);
                }

                return $realAccess->deliveryAuthorized($id);
            });
        });
        app(OutboxWorker::class)->runOnce();
        Http::assertNothingSent();
        $this->assertDatabaseHas('notification_outbox', ['id' => $row->id, 'status' => 'retry', 'attempts' => 0]);
        $this->assertDatabaseCount('outbox_authorizations', 1);
    }

    public function test_real_reconciliation_boundary_conflict_blocks_already_queued_final_notification(): void
    {
        if (! class_exists(Reconciler::class)) {
            $this->markTestSkipped('P10 reconciliation integration is not installed yet.');
        }
        config([
            'blockchain.chains.ethereum.providers.primary.history_start_block' => 0,
            'blockchain.chains.ethereum.providers.secondary.history_start_block' => 0,
        ]);
        $row = $this->queued();
        $set = DB::table('watch_sets')->insertGetId(['chain' => 'ethereum', 'version' => 1, 'cutover_block' => 9, 'activated_at' => now()]);
        DB::table('watch_set_wallets')->insert(['watch_set_id' => $set, 'wallet_id' => DB::table('wallets')->value('id')]);
        $cursor = DB::table('scan_cursors')->insertGetId([
            'chain' => 'ethereum', 'role' => 'primary', 'provider' => 'primary', 'watch_set_id' => $set,
            'last_block' => 10, 'last_block_hash' => '0x'.str_repeat('b', 64),
        ]);
        DB::table('scan_ranges')->insert([
            'cursor_id' => $cursor, 'watch_set_id' => $set, 'from_block' => 10, 'to_block' => 10,
            'to_block_hash' => '0x'.str_repeat('b', 64), 'status' => 'completed', 'completed_at' => now(),
        ]);
        $client = static fn (string $role, string $hash) => new class($role, $hash) implements ChainClient
        {
            public function __construct(private string $role, private string $hash) {}

            public function chain(): string
            {
                return 'ethereum';
            }

            public function provider(): string
            {
                return $this->role;
            }

            public function verifyNetwork(): void {}

            public function finalizedBlock(): array
            {
                return $this->block(10);
            }

            public function block(int $number): array
            {
                return ['number' => $number, 'hash' => $this->hash, 'timestamp' => 1793038000];
            }

            public function logs(int $from, int $to, array $normalizedWatchedAddresses, string $direction): array
            {
                throw new \LogicException('Boundary conflict must stop before logs.');
            }

            public function receipt(string $transactionHash): array
            {
                throw new \LogicException('Boundary conflict must stop before receipts.');
            }
        };
        try {
            app(Reconciler::class)->reconcile($client('primary', '0x'.str_repeat('b', 64)), $client('secondary', '0x'.str_repeat('f', 64)));
            $this->fail('Expected the conflicting finalized boundary to pause coverage.');
        } catch (ScanException) {
            $this->assertNotNull(DB::table('scan_cursors')->find($cursor)->paused_at);
            $this->assertDatabaseHas('reconciliation_checks', ['failure_code' => 'boundary_hash_mismatch']);
        }
        app(OutboxWorker::class)->runOnce();
        Http::assertNothingSent();
        $this->assertDatabaseHas('notification_outbox', ['id' => $row->id, 'status' => 'retry', 'attempts' => 0]);
        $this->assertDatabaseCount('transfers', 1);
    }

    private function update(int $id, string $actor, string $text): array
    {
        return ['update_id' => $id, 'message' => ['from' => ['id' => (int) $actor, 'is_bot' => false], 'chat' => ['id' => (int) $actor, 'type' => 'private'], 'text' => $text]];
    }

    private function user(string $actor, string $label): array
    {
        $access = app(AccessService::class);
        $activation = $access->activate($access->issue()->token, $actor);
        $walletId = DB::table('wallets')->where('chain', 'ethereum')->where('address', '0x'.str_repeat('1', 40))->value('id');
        $walletId ??= DB::table('wallets')->insertGetId(['chain' => 'ethereum', 'address' => '0x'.str_repeat('1', 40)]);
        $userWallet = DB::table('user_wallets')->insertGetId(['user_id' => $activation->userId, 'wallet_id' => $walletId, 'label' => $label, 'start_block' => 0]);
        $destination = DB::table('chat_destinations')->insertGetId(['chat_id' => $actor, 'kind' => 'private']);
        DB::table('user_chat_destinations')->insert(['user_id' => $activation->userId, 'destination_id' => $destination, 'enabled' => true]);
        $subscription = DB::table('wallet_subscriptions')->insertGetId(['user_id' => $activation->userId, 'user_wallet_id' => $userWallet, 'destination_id' => $destination]);

        return ['user' => $activation->userId, 'grant' => $activation->grantId, 'destination' => $destination, 'subscription' => $subscription];
    }

    private function transfer(): int
    {
        return DB::table('transfers')->insertGetId([
            'chain' => 'ethereum', 'transaction_hash' => '0x'.str_repeat('a', 64), 'receipt_log_position' => 0,
            'contract_address' => '0xdac17f958d2ee523a2206206994597c13d831ec7',
            'from_address' => '0x'.str_repeat('2', 40), 'to_address' => '0x'.str_repeat('1', 40),
            'amount_raw' => '1000001', 'block_number' => 10, 'block_hash' => '0x'.str_repeat('b', 64),
            'block_timestamp' => 1793038000, 'finality' => 'finalized',
        ]);
    }

    private function queued(): object
    {
        $this->user('1001', 'Alice');
        app(OutboxRouter::class)->enqueue($this->transfer());

        return DB::table('notification_outbox')->first();
    }

    private function fakeSendSuccess(): void
    {
        Http::fake(fn ($request) => Http::response(['ok' => true, 'result' => ['message_id' => 42, 'chat' => ['id' => (int) $request['chat_id']]]]));
    }
}
