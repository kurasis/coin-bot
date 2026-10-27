<?php

namespace Tests\Feature;

use App\Access\AccessService;
use App\Telegram\TelegramPoller;
use App\Wallets\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SecurityAccessAuditTest extends TestCase
{
    use RefreshDatabase;

    private array $batches = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config([
            'telegram.token' => '123456:security_access_fixture',
            'telegram.poll_timeout' => 0,
            'telegram.allowed_user_ids' => '',
        ]);
        Http::fake([
            'https://api.telegram.org/*/getUpdates' => fn () => Http::response([
                'ok' => true, 'result' => array_shift($this->batches) ?? [],
            ]),
        ]);
    }

    public function test_authenticated_text_cannot_dispatch_operator_commands_or_override_the_actor(): void
    {
        [$alice, $bob] = $this->twoUsers();
        $wallet = app(WalletService::class)->add($bob, 'ethereum', '0x'.str_repeat('a', 40), 'Bob private wallet', 100);
        $before = $this->protectedState();
        $texts = [
            '/access:issue --operator=local-console',
            '/access:revoke-grant '.$bob,
            '/wallet:remove '.$wallet->id.' --user='.$bob,
            '/stop {"user_id":'.$bob.',"role":"admin","organization_id":42}',
        ];
        $updates = [];
        foreach ($texts as $index => $text) {
            $update = $this->update(10 + $index, '11001', $text);
            $update['message']['user_id'] = $bob;
            $update['message']['role'] = 'admin';
            $update['message']['organization_id'] = 42;
            $update['message']['from']['username'] = 'local-console';
            $updates[] = $update;
        }

        $this->assertSame(4, $this->poll($updates));
        $this->assertSame($before, $this->protectedState());
        $this->assertDatabaseCount('access_keys', 2);
        $this->assertDatabaseCount('access_grants', 2);
        $this->assertDatabaseCount('user_wallets', 1);
        $this->assertDatabaseCount('notification_outbox', 0);
        $this->assertSame(4, DB::table('telegram_replies')->where('update_id', '>=', 10)
            ->where('user_id', $alice)->where('chat_id', '11001')
            ->where('text', 'Access active. Notifications: enabled.')->count());

        $this->assertSame(1, $this->poll([$this->update(20, '11001', '/stop')]));
        $this->assertDatabaseHas('user_chat_destinations', ['user_id' => $alice, 'enabled' => 0]);
        $this->assertDatabaseHas('user_chat_destinations', ['user_id' => $bob, 'enabled' => 1]);
        $this->assertSame(1, $this->poll([$this->update(21, '11001', '/resume')]));
        $this->assertDatabaseHas('user_chat_destinations', ['user_id' => $alice, 'enabled' => 1]);
        Http::assertNotSent(fn ($request) => ! str_ends_with($request->url(), '/getUpdates'));
    }

    public function test_key_consumption_pins_allowlist_private_chat_actor_and_update_identity(): void
    {
        config(['telegram.allowed_user_ids' => '11001,22002']);
        $access = app(AccessService::class);
        $aliceKey = $access->issue(operatorId: 'security-audit-operator');
        $bobKey = $access->issue(operatorId: 'security-audit-operator');
        $mismatch = $this->update(101, '11001', '/start '.$aliceKey->token);
        $mismatch['message']['chat']['id'] = 22002;
        $activation = $this->update(102, '11001', '/start '.$aliceKey->token);
        $duplicate = $this->update(102, '22002', '/start '.$aliceKey->token);

        $this->assertSame(5, $this->poll([
            $this->update(100, '33003', '/start '.$aliceKey->token),
            $mismatch, $activation, $duplicate,
            $this->update(103, '22002', '/start '.$aliceKey->token),
            $this->update(104, '22002', '/start '.$bobKey->token),
        ]));
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('access_grants', 2);
        $this->assertDatabaseCount('telegram_replies', 2);
        $this->assertDatabaseCount('chat_destinations', 2);
        $this->assertDatabaseMissing('users', ['telegram_user_id' => '33003']);
        $this->assertSame('11001', DB::table('users')->where('id', DB::table('access_keys')
            ->where('id', $aliceKey->id)->value('redeemed_by_user_id'))->value('telegram_user_id'));
        $this->assertSame('22002', DB::table('users')->where('id', DB::table('access_keys')
            ->where('id', $bobKey->id)->value('redeemed_by_user_id'))->value('telegram_user_id'));
        $this->assertSame(0, $this->poll([$activation, $duplicate]));
        $this->assertDatabaseCount('access_grants', 2);
        $this->assertDatabaseHas('telegram_update_offsets', ['next_update_id' => 105]);
        $stored = json_encode([
            DB::table('access_keys')->get(), DB::table('audit_events')->get(),
            DB::table('telegram_updates')->get(), DB::table('telegram_replies')->get(),
        ], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($aliceKey->token, $stored);
        $this->assertStringNotContainsString($bobKey->token, $stored);
        Http::assertNotSent(fn ($request) => ! str_ends_with($request->url(), '/getUpdates'));
    }

    public function test_inbound_activation_throttling_is_per_actor_and_expires_without_consuming_keys(): void
    {
        config(['access.activation_attempts' => 2, 'access.activation_decay_seconds' => 300]);
        $access = app(AccessService::class);
        $aliceKey = $access->issue(operatorId: 'security-audit-operator');
        $bobKey = $access->issue(operatorId: 'security-audit-operator');

        $this->assertSame(4, $this->poll([
            $this->update(1, '11001', '/start '.str_repeat('x', 43)),
            $this->update(2, '11001', '/start '.str_repeat('y', 43)),
            $this->update(3, '11001', '/start '.$aliceKey->token),
            $this->update(4, '22002', '/start '.$bobKey->token),
        ]));
        $this->assertNull(DB::table('access_keys')->where('id', $aliceKey->id)->value('redeemed_at'));
        $this->assertDatabaseMissing('users', ['telegram_user_id' => '11001']);
        $this->assertDatabaseHas('users', ['telegram_user_id' => '22002']);
        $this->assertDatabaseCount('access_grants', 1);
        $this->assertDatabaseCount('telegram_replies', 1);

        $this->travel(301)->seconds();
        $this->assertSame(1, $this->poll([$this->update(5, '11001', '/start '.$aliceKey->token)]));
        $this->assertNotNull(DB::table('access_keys')->where('id', $aliceKey->id)->value('redeemed_at'));
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('access_grants', 2);
        $this->assertDatabaseCount('telegram_replies', 2);
        Http::assertNotSent(fn ($request) => ! str_ends_with($request->url(), '/getUpdates'));
    }

    public function test_anonymous_update_retention_is_bounded_without_reopening_old_activation_updates(): void
    {
        [$alice, $bob] = $this->twoUsers();
        config(['telegram.allowed_user_ids' => '11001,22002']);
        $replies = DB::table('telegram_replies')->orderBy('id')->get()->toJson();
        $unused = app(AccessService::class)->issue(operatorId: 'security-audit-operator');
        $protected = $this->protectedState();
        $rows = [];
        for ($id = 3; $id <= 1002; $id++) {
            $rows[] = ['update_id' => $id, 'result' => 'ignored', 'processed_at' => now()];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('telegram_updates')->insert($chunk);
        }
        DB::table('telegram_update_offsets')->where('worker', 'main')->update(['next_update_id' => 1003]);
        $updates = [];
        for ($id = 1003; $id <= 1022; $id++) {
            $updates[] = $this->update($id, '33003', '/start '.$unused->token);
        }

        $this->assertSame(20, $this->poll($updates));
        $anonymous = DB::table('telegram_updates')->whereNull('user_id')->whereNull('access_key_id');
        $this->assertSame(1000, $anonymous->count());
        $this->assertSame(23, (int) $anonymous->min('update_id'));
        $this->assertDatabaseHas('telegram_updates', ['update_id' => 1, 'user_id' => $alice, 'result' => 'activated']);
        $this->assertDatabaseHas('telegram_updates', ['update_id' => 2, 'user_id' => $bob, 'result' => 'activated']);
        $this->assertDatabaseHas('telegram_update_offsets', ['next_update_id' => 1023]);
        $this->assertSame($replies, DB::table('telegram_replies')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('access_grants', 2);
        $this->assertDatabaseCount('notification_outbox', 0);

        // A pruned ID is still acknowledged by the durable cursor, even if its
        // replay contains an otherwise valid unused key for an allowed actor.
        $this->assertSame(0, $this->poll([$this->update(3, '11001', '/start '.$unused->token)]));
        $this->assertNull(DB::table('access_keys')->where('id', $unused->id)->value('redeemed_at'));
        $this->assertDatabaseCount('access_grants', 2);
        $this->assertSame($replies, DB::table('telegram_replies')->orderBy('id')->get()->toJson());
        $this->assertSame($protected, $this->protectedState());
        Http::assertNotSent(fn ($request) => ! str_ends_with($request->url(), '/getUpdates'));
    }

    public function test_idle_polls_prune_legacy_anonymous_backlogs_in_bounded_chunks_only(): void
    {
        [$alice, $bob] = $this->twoUsers();
        $unused = app(AccessService::class)->issue(operatorId: 'security-audit-operator');
        DB::table('telegram_updates')->insert([
            'update_id' => 1503, 'access_key_id' => $unused->id, 'result' => 'ignored', 'processed_at' => now(),
        ]);
        $rows = [];
        for ($id = 3; $id <= 1502; $id++) {
            $rows[] = ['update_id' => $id, 'result' => 'ignored', 'processed_at' => now()];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('telegram_updates')->insert($chunk);
        }
        DB::table('telegram_update_offsets')->where('worker', 'main')->update(['next_update_id' => 2504]);
        $protected = $this->protectedState();
        $replies = DB::table('telegram_replies')->orderBy('id')->get()->toJson();

        $this->assertSame(0, $this->poll([]));
        $anonymous = DB::table('telegram_updates')->whereNull('user_id')->whereNull('access_key_id');
        $this->assertSame(500, $anonymous->count());
        $this->assertSame(1003, (int) $anonymous->min('update_id'));
        $this->assertDatabaseHas('telegram_updates', ['update_id' => 1, 'user_id' => $alice]);
        $this->assertDatabaseHas('telegram_updates', ['update_id' => 2, 'user_id' => $bob]);
        $this->assertDatabaseHas('telegram_updates', ['update_id' => 1503, 'access_key_id' => $unused->id]);
        $this->assertSame(0, $this->poll([]));
        $this->assertSame(0, $anonymous->count());
        $this->assertSame(3, DB::table('telegram_updates')->count());
        $this->assertDatabaseHas('telegram_update_offsets', ['next_update_id' => 2504]);
        $this->assertSame($protected, $this->protectedState());
        $this->assertSame($replies, DB::table('telegram_replies')->orderBy('id')->get()->toJson());
        Http::assertNotSent(fn ($request) => ! str_ends_with($request->url(), '/getUpdates'));
    }

    #[DataProvider('inactiveAccounts')]
    public function test_known_inactive_users_cannot_bypass_anonymous_retention(string $state): void
    {
        [$alice, $bob] = $this->twoUsers();
        if ($state === 'revoked') {
            app(AccessService::class)->revokeGrant((int) DB::table('access_grants')->where('user_id', $alice)->value('id'));
        } elseif ($state === 'expired') {
            DB::table('access_grants')->where('user_id', $alice)->update(['expires_at' => now()->subSecond()]);
        } else {
            DB::table('users')->where('id', $alice)->update(['state' => 'disabled']);
        }
        $this->travel(61)->seconds();
        $protected = $this->protectedState();
        $replies = DB::table('telegram_replies')->orderBy('id')->get()->toJson();
        $rows = [];
        for ($id = 3; $id <= 1002; $id++) {
            $rows[] = ['update_id' => $id, 'result' => 'ignored', 'processed_at' => now()];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('telegram_updates')->insert($chunk);
        }
        DB::table('telegram_update_offsets')->where('worker', 'main')->update(['next_update_id' => 1003]);
        $updates = [];
        for ($id = 1003; $id <= 1022; $id++) {
            $updates[] = $this->update($id, '11001', $id % 2 === 0 ? '/status' : '/start '.str_repeat('x', 43));
        }

        $this->assertSame(20, $this->poll($updates));
        $this->assertSame(20, DB::table('telegram_updates')->whereBetween('update_id', [1003, 1022])
            ->whereNull('user_id')->whereNull('access_key_id')->count());
        $this->assertSame(1000, DB::table('telegram_updates')->whereNull('user_id')->whereNull('access_key_id')->count());
        $this->assertSame($protected, $this->protectedState());
        $this->assertSame($replies, DB::table('telegram_replies')->orderBy('id')->get()->toJson());
        $this->assertDatabaseHas('telegram_updates', ['update_id' => 1, 'user_id' => $alice, 'result' => 'activated']);
        $this->assertDatabaseHas('telegram_updates', ['update_id' => 2, 'user_id' => $bob, 'result' => 'activated']);

        $this->assertSame(1, $this->poll([$this->update(2022, '22002', '/status')]));
        $this->assertSame(0, DB::table('telegram_updates')->whereNull('user_id')->whereNull('access_key_id')->count());
        $this->assertDatabaseCount('telegram_updates', 3);
        $this->assertDatabaseCount('telegram_replies', 3);
        $this->assertDatabaseHas('telegram_updates', ['update_id' => 2022, 'user_id' => $bob, 'result' => 'status']);
        $this->assertSame($protected, $this->protectedState());
        Http::assertNotSent(fn ($request) => ! str_ends_with($request->url(), '/getUpdates'));
    }

    public static function inactiveAccounts(): array
    {
        return [['revoked'], ['expired'], ['disabled']];
    }

    private function twoUsers(): array
    {
        $access = app(AccessService::class);
        $alice = $access->issue(operatorId: 'security-audit-operator');
        $bob = $access->issue(operatorId: 'security-audit-operator');
        $this->assertSame(2, $this->poll([
            $this->update(1, '11001', '/start '.$alice->token),
            $this->update(2, '22002', '/start '.$bob->token),
        ]));

        return [
            (int) DB::table('users')->where('telegram_user_id', '11001')->value('id'),
            (int) DB::table('users')->where('telegram_user_id', '22002')->value('id'),
        ];
    }

    private function protectedState(): string
    {
        $state = [];
        foreach (['users', 'access_keys', 'access_grants', 'chat_destinations', 'user_chat_destinations',
            'wallets', 'user_wallets', 'wallet_subscriptions', 'audit_events'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        }

        return json_encode($state, JSON_THROW_ON_ERROR);
    }

    private function poll(array $updates): int
    {
        $this->batches[] = $updates;

        return app(TelegramPoller::class)->runOnce();
    }

    private function update(int $id, string $actor, string $text): array
    {
        return ['update_id' => $id, 'message' => [
            'from' => ['id' => (int) $actor, 'is_bot' => false],
            'chat' => ['id' => (int) $actor, 'type' => 'private'],
            'text' => $text,
        ]];
    }
}
