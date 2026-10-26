<?php

namespace Tests\Feature;

use App\Access\AccessService;
use App\Monitoring\OutboxRouter;
use App\Telegram\OutboxWorker;
use App\Telegram\ReplyWorker;
use App\Telegram\TelegramAudience;
use App\Telegram\TelegramPoller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TelegramPrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'telegram.token' => '123456:privacy_fixture',
            'telegram.poll_timeout' => 0,
            'telegram.allowed_user_ids' => '',
        ]);
        $this->freezeTime();
    }

    #[DataProvider('audienceSettings')]
    public function test_audience_configuration_requires_exact_ids_and_rejects_malformed_lists(mixed $setting, string $actor, bool $allowed): void
    {
        config(['telegram.allowed_user_ids' => $setting]);

        $this->assertSame($allowed, app(TelegramAudience::class)->allows($actor));
    }

    public static function audienceSettings(): array
    {
        return [
            'blank invitation mode' => ['', '1001', true],
            'whitespace invitation mode' => [" \t ", '1001', true],
            'exact member' => ['1001,2002', '1001', true],
            'trimmed member' => [' 1001 , 2002 ', '2002', true],
            'duplicate member' => ['1001,1001', '1001', true],
            'unlisted id' => ['1001,2002', '3003', false],
            'substring is not membership' => ['11001', '1001', false],
            'invalid empty member' => ['1001,,2002', '1001', false],
            'invalid trailing comma' => ['1001,', '1001', false],
            'invalid leading comma' => [',1001', '1001', false],
            'invalid username' => ['1001,@someone', '1001', false],
            'invalid wildcard' => ['*', '1001', false],
            'invalid negative id' => ['1001,-2002', '1001', false],
            'invalid padded id' => ['1001,02002', '1001', false],
            'invalid numeric notation' => ['1001,2e3', '1001', false],
            'boolean configuration' => [false, '1001', false],
            'integer configuration' => [1001, '1001', false],
            'null configuration' => [null, '1001', false],
            'array configuration' => [['1001'], '1001', false],
            'invalid actor in invitation mode' => ['', '-1001', false],
            'padded actor in invitation mode' => ['', '01001', false],
        ];
    }

    public function test_uninvited_commands_and_invalid_keys_are_silent_but_offsets_advance(): void
    {
        $access = app(AccessService::class);
        $expired = $access->issue();
        DB::table('access_keys')->where('id', $expired->id)->update(['expires_at' => now()->subSecond()]);
        $revoked = $access->issue();
        $access->revokeKey($revoked->id);
        $texts = ['/start', '/status', 'Hello', '/stop', '/resume', '/start malformed', '/start '.str_repeat('x', 43), '/start '.$expired->token, '/start '.$revoked->token];
        $updates = [];
        foreach ($texts as $index => $text) {
            $updates[] = $this->update(100 + $index, '1001', $text);
        }
        $this->fakeUpdates($updates);

        $this->assertSame(count($updates), app(TelegramPoller::class)->runOnce());
        $this->assertFalse(app(ReplyWorker::class)->runOnce());

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('access_grants', 0);
        $this->assertDatabaseCount('telegram_replies', 0);
        $this->assertDatabaseCount('telegram_updates', count($updates));
        $this->assertDatabaseHas('telegram_update_offsets', ['next_update_id' => 109]);
        $this->assertStringNotContainsString($expired->token, json_encode(DB::table('telegram_updates')->get()));
        $this->assertStringNotContainsString($revoked->token, json_encode(DB::table('telegram_updates')->get()));
        $this->assertNoMessagesSent();
    }

    public function test_an_unlisted_user_cannot_consume_a_valid_invitation_key(): void
    {
        config(['telegram.allowed_user_ids' => '2002']);
        $key = app(AccessService::class)->issue();
        $this->fakeUpdates([$this->update(200, '1001', '/start '.$key->token)]);

        app(TelegramPoller::class)->runOnce();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('access_grants', 0);
        $this->assertDatabaseCount('telegram_replies', 0);
        $this->assertNull(DB::table('access_keys')->where('id', $key->id)->value('redeemed_at'));
        $this->assertDatabaseHas('telegram_update_offsets', ['next_update_id' => 201]);
        $this->assertNoMessagesSent();
    }

    public function test_allowlisting_alone_does_not_authorize_a_user_but_a_valid_key_does(): void
    {
        config(['telegram.allowed_user_ids' => '1001,2002']);
        $key = app(AccessService::class)->issue();
        $this->fakeUpdates([
            $this->update(210, '1001', '/start '.$key->token),
            $this->update(211, '2002', '/status'),
        ]);

        app(TelegramPoller::class)->runOnce();
        app(ReplyWorker::class)->runOnce();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('access_grants', 1);
        $this->assertDatabaseCount('telegram_replies', 1);
        $this->assertDatabaseHas('telegram_replies', ['chat_id' => '1001', 'status' => 'sent']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage') && $request['chat_id'] === '1001');
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/sendMessage') && $request['chat_id'] === '2002');
    }

    #[DataProvider('inactiveAccessStates')]
    public function test_lapsed_users_stay_silent_even_when_their_id_is_allowlisted(string $state): void
    {
        $user = $this->user('1001');
        $this->deactivate($user, $state);
        config(['telegram.allowed_user_ids' => '1001']);
        $this->fakeUpdates([
            $this->update(220, '1001', '/status'),
            $this->update(221, '1001', '/resume'),
            $this->update(222, '1001', '/stop'),
        ]);

        app(TelegramPoller::class)->runOnce();

        $this->assertDatabaseCount('telegram_replies', 0);
        $this->assertDatabaseHas('telegram_update_offsets', ['next_update_id' => 223]);
        $this->assertNoMessagesSent();
    }

    public static function inactiveAccessStates(): array
    {
        return [['revoked'], ['expired'], ['disabled'], ['key_revoked']];
    }

    public function test_group_bot_and_mismatched_chat_updates_cannot_redeem_an_invitation(): void
    {
        config(['telegram.allowed_user_ids' => '1001']);
        $key = app(AccessService::class)->issue();
        $group = $this->update(230, '1001', '/start '.$key->token);
        $group['message']['chat'] = ['id' => -1001, 'type' => 'group'];
        $bot = $this->update(231, '1001', '/start '.$key->token);
        $bot['message']['from']['is_bot'] = true;
        $mismatch = $this->update(232, '1001', '/start '.$key->token);
        $mismatch['message']['chat']['id'] = 2002;
        $this->fakeUpdates([$group, $bot, $mismatch]);

        app(TelegramPoller::class)->runOnce();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('access_grants', 0);
        $this->assertDatabaseCount('telegram_replies', 0);
        $this->assertNull(DB::table('access_keys')->where('id', $key->id)->value('redeemed_at'));
        $this->assertNoMessagesSent();
    }

    #[DataProvider('unsafeReplyStates')]
    public function test_legacy_or_no_longer_authorized_replies_are_cancelled_without_http(string $state): void
    {
        $user = $this->user('1001');
        $row = [
            'update_id' => 300, 'chat_id' => '1001', 'user_id' => $user['user'],
            'text' => 'Previously queued private confirmation.', 'status' => 'pending', 'available_at' => now(),
        ];
        if ($state === 'unknown') {
            $row['user_id'] = null;
        } elseif ($state === 'wrong_chat') {
            $row['chat_id'] = '2002';
        } elseif ($state === 'removed') {
            config(['telegram.allowed_user_ids' => '2002']);
        } else {
            $this->deactivate($user, $state);
        }
        $id = DB::table('telegram_replies')->insertGetId($row);

        $this->assertTrue(app(ReplyWorker::class)->runOnce());

        $this->assertDatabaseHas('telegram_replies', ['id' => $id, 'status' => 'cancelled']);
        Http::assertNothingSent();
    }

    public static function unsafeReplyStates(): array
    {
        return [['unknown'], ['wrong_chat'], ['removed'], ['revoked'], ['expired'], ['disabled'], ['key_revoked']];
    }

    public function test_removing_one_user_from_the_allowlist_cancels_queued_transfers_without_blocking_another_user(): void
    {
        config(['telegram.allowed_user_ids' => '1001,2002']);
        $alice = $this->user('1001');
        $bob = $this->user('2002');
        $this->subscribe($alice);
        $this->subscribe($bob);
        app(OutboxRouter::class)->enqueue($this->transfer());
        $this->assertDatabaseCount('notification_outbox', 2);
        config(['telegram.allowed_user_ids' => '2002']);
        $this->fakeUpdates([]);

        $this->assertTrue(app(OutboxWorker::class)->runOnce());
        $this->assertTrue(app(OutboxWorker::class)->runOnce());

        $this->assertDatabaseHas('notification_outbox', ['destination_id' => $alice['destination'], 'status' => 'cancelled']);
        $this->assertDatabaseHas('notification_outbox', ['destination_id' => $bob['destination'], 'status' => 'sent']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage') && $request['chat_id'] === '2002');
    }

    public function test_unlisted_users_do_not_receive_new_notification_routes_or_failed_delivery_replays(): void
    {
        $user = $this->user('1001');
        $this->subscribe($user);
        app(OutboxRouter::class)->enqueue($this->transfer());
        $id = (int) DB::table('notification_outbox')->value('id');
        DB::table('notification_outbox')->where('id', $id)->update(['status' => 'failed']);
        config(['telegram.allowed_user_ids' => '2002']);
        $this->assertCount(0, app(OutboxRouter::class)->authorizedRoutes($id));
        app(OutboxRouter::class)->enqueue($this->transfer('c'));
        $this->assertDatabaseCount('notification_outbox', 1);
        Http::assertNothingSent();

        $this->expectException(LogicException::class);
        app(OutboxWorker::class)->replay($id);
    }

    private function update(int $id, string $actor, string $text): array
    {
        return ['update_id' => $id, 'message' => ['from' => ['id' => (int) $actor, 'is_bot' => false], 'chat' => ['id' => (int) $actor, 'type' => 'private'], 'text' => $text]];
    }

    private function fakeUpdates(array $updates): void
    {
        Http::fake([
            '*/getUpdates' => Http::response(['ok' => true, 'result' => $updates]),
            '*/sendMessage' => fn ($request) => Http::response(['ok' => true, 'result' => ['message_id' => 42, 'chat' => ['id' => (int) $request['chat_id']]]]),
        ]);
    }

    private function assertNoMessagesSent(): void
    {
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/sendMessage'));
    }

    private function user(string $actor): array
    {
        $access = app(AccessService::class);
        $key = $access->issue();
        $activation = $access->activate($key->token, $actor);
        $destination = DB::table('chat_destinations')->insertGetId(['chat_id' => $actor, 'kind' => 'private']);
        DB::table('user_chat_destinations')->insert(['user_id' => $activation->userId, 'destination_id' => $destination, 'enabled' => true]);

        return ['user' => $activation->userId, 'grant' => $activation->grantId, 'key' => $key->id, 'destination' => $destination];
    }

    private function deactivate(array $user, string $state): void
    {
        match ($state) {
            'revoked' => app(AccessService::class)->revokeGrant($user['grant']),
            'expired' => DB::table('access_grants')->where('id', $user['grant'])->update(['expires_at' => now()->subSecond()]),
            'disabled' => DB::table('users')->where('id', $user['user'])->update(['state' => 'disabled']),
            'key_revoked' => app(AccessService::class)->revokeKey($user['key']),
        };
    }

    private function subscribe(array $user): void
    {
        $wallet = DB::table('wallets')->where('chain', 'ethereum')->where('address', '0x'.str_repeat('1', 40))->value('id');
        $wallet ??= DB::table('wallets')->insertGetId(['chain' => 'ethereum', 'address' => '0x'.str_repeat('1', 40)]);
        $userWallet = DB::table('user_wallets')->insertGetId(['user_id' => $user['user'], 'wallet_id' => $wallet, 'start_block' => 0]);
        DB::table('wallet_subscriptions')->insert(['user_id' => $user['user'], 'user_wallet_id' => $userWallet, 'destination_id' => $user['destination']]);
    }

    private function transfer(string $hashCharacter = 'a'): int
    {
        return DB::table('transfers')->insertGetId([
            'chain' => 'ethereum', 'transaction_hash' => '0x'.str_repeat($hashCharacter, 64), 'receipt_log_position' => 0,
            'contract_address' => '0xdac17f958d2ee523a2206206994597c13d831ec7',
            'from_address' => '0x'.str_repeat('2', 40), 'to_address' => '0x'.str_repeat('1', 40),
            'amount_raw' => '1000001', 'block_number' => 10, 'block_hash' => '0x'.str_repeat('b', 64),
            'block_timestamp' => 1793038000, 'finality' => 'finalized',
        ]);
    }
}
