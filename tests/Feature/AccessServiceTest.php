<?php

namespace Tests\Feature;

use App\Access\AccessDenied;
use App\Access\AccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AccessServiceTest extends TestCase
{
    use RefreshDatabase;

    private AccessService $access;

    protected function setUp(): void
    {
        parent::setUp();
        $this->access = app(AccessService::class);
    }

    public function test_key_has_256_random_bits_and_only_its_hash_is_stored(): void
    {
        $issued = $this->access->issue(operatorId: 'test-operator');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $issued->token);
        $this->assertSame(32, strlen(base64_decode(strtr($issued->token, '-_', '+/').'=', true)));
        $record = DB::table('access_keys')->find($issued->id);
        $this->assertSame(hash('sha256', $issued->token), $record->token_hash);
        $stored = json_encode([
            DB::table('access_keys')->get(), DB::table('access_grants')->get(), DB::table('audit_events')->get(),
        ], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($issued->token, $stored);
        $this->assertSame('test-operator', DB::table('audit_events')->first()->operator_id);
        $this->assertEqualsWithDelta(24 * 3600, now()->diffInSeconds($issued->expiresAt), 2);
    }

    public function test_activation_binds_numeric_actor_and_replayed_updates_are_idempotent(): void
    {
        $issued = $this->access->issue();
        $activated = $this->access->activate($issued->token, '123456789', 100);
        $replayed = $this->access->activate($issued->token, '123456789', 100);
        $this->assertSame($activated->userId, $replayed->userId);
        $this->assertSame($activated->grantId, $replayed->grantId);
        $this->assertTrue($replayed->replayed);
        $this->assertSame(1, DB::table('access_grants')->count());
        $this->assertSame(1, DB::table('telegram_updates')->count());
        $this->assertSame('123456789', DB::table('users')->find($activated->userId)->telegram_user_id);
        $this->assertSame($activated->grantId, $this->access->requireGrant($activated->userId)->id);
        $this->assertNull(DB::table('audit_events')->where('action', 'grant.activated')->first()->operator_id);

        $this->deny(fn () => $this->access->activate($issued->token, '987654321', 100));
        $this->deny(fn () => $this->access->activate($issued->token, '123456789', 101));
        $this->deny(fn () => $this->access->activate($issued->token, '123456789'));
        $this->assertSame(1, DB::table('users')->count());
    }

    public function test_expiry_and_revocation_prevent_activation_without_creating_users(): void
    {
        $this->freezeTime();
        $expired = $this->access->issue(now()->addMinute());
        $revoked = $this->access->issue();
        $this->access->revokeKey($revoked->id);
        $this->travel(2)->minutes();
        $this->deny(fn () => $this->access->activate($expired->token, '100'));
        $this->deny(fn () => $this->access->activate($revoked->token, '101'));
        $this->deny(fn () => $this->access->activate(str_repeat('a', 43), '102'));
        $this->deny(fn () => $this->access->activate('malformed', '103'));
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('access_grants')->count());
        $this->assertNull(DB::table('access_keys')->find($expired->id)->redeemed_at);
    }

    public function test_grant_lifetime_is_independent_of_activation_key_lifetime(): void
    {
        $this->freezeTime();
        $issued = $this->access->issue(now()->addMinute(), now()->addHour());
        $activated = $this->access->activate($issued->token, '110');
        $this->travel(2)->minutes();
        $this->assertNotNull($this->access->activeGrant($activated->userId));
        $this->travel(60)->minutes();
        $this->assertNull($this->access->activeGrant($activated->userId));
        $this->deny(fn () => $this->access->requireGrant($activated->userId));
    }

    public function test_rate_limiting_is_per_actor_and_does_not_consume_a_valid_key(): void
    {
        config(['access.activation_attempts' => 2]);
        $issued = $this->access->issue();
        $this->deny(fn () => $this->access->activate('invalid', '120'));
        $this->deny(fn () => $this->access->activate('invalid', '120'));
        $this->deny(fn () => $this->access->activate($issued->token, '120'));
        $this->assertNull(DB::table('access_keys')->find($issued->id)->redeemed_at);
        $activated = $this->access->activate($issued->token, '121');
        $this->assertNotNull($this->access->activeGrant($activated->userId));
        RateLimiter::clear('access:activation:'.hash('sha256', '120'));
    }

    public function test_inactive_accounts_cannot_reactivate_and_invalid_actors_are_rejected(): void
    {
        $first = $this->access->issue();
        $activated = $this->access->activate($first->token, '130');
        DB::table('users')->where('id', $activated->userId)->update(['state' => 'disabled']);
        $second = $this->access->issue();
        $this->deny(fn () => $this->access->activate($second->token, '130'));
        $this->deny(fn () => $this->access->activate($second->token, '@username'));
        $this->deny(fn () => $this->access->activate($second->token, '-100'));
        $this->assertNull($this->access->activeGrant($activated->userId));
        $this->assertNull(DB::table('access_keys')->find($second->id)->redeemed_at);
    }

    public function test_revoking_one_authorizer_preserves_delivery_for_another_valid_user(): void
    {
        [$first, $second, $outbox] = $this->sharedDelivery();
        $this->assertTrue($this->access->deliveryAuthorized($outbox));
        $this->access->revokeGrant($first->grantId, 'operator');
        $this->assertTrue($this->access->deliveryAuthorized($outbox));
        $this->assertSame('pending', DB::table('notification_outbox')->find($outbox)->status);
        $this->assertSame(1, DB::table('outbox_authorizations')->where('outbox_id', $outbox)->count());
        $this->assertNull($this->access->activeGrant($first->userId));
        $this->assertNotNull($this->access->activeGrant($second->userId));
        $this->access->revokeGrant($second->grantId);
        $this->assertFalse($this->access->deliveryAuthorized($outbox));
        $this->assertSame('cancelled', DB::table('notification_outbox')->find($outbox)->status);
    }

    public function test_inactive_permissions_or_expired_grants_do_not_preserve_authorization(): void
    {
        [$first, $second, $outbox] = $this->sharedDelivery();
        DB::table('user_chat_destinations')->where('user_id', $second->userId)->update(['enabled' => false]);
        $this->access->revokeGrant($first->grantId);
        $this->assertFalse($this->access->deliveryAuthorized($outbox));
        $this->assertSame('cancelled', DB::table('notification_outbox')->find($outbox)->status);
    }

    public function test_revoking_a_redeemed_key_revokes_its_grant_and_replay_access(): void
    {
        $key = $this->access->issue();
        $activated = $this->access->activate($key->token, '140', 140);
        $this->access->revokeKey($key->id);
        $this->assertNull($this->access->activeGrant($activated->userId));
        $this->deny(fn () => $this->access->activate($key->token, '140', 140));
    }

    public function test_fail_closed_restore_invalidates_consumed_and_outstanding_access_but_preserves_data(): void
    {
        [$first, $second, $outbox] = $this->sharedDelivery();
        $unused = $this->access->issue();
        $this->access->invalidateRestored('recovery');
        $this->assertNull($this->access->activeGrant($first->userId));
        $this->assertNull($this->access->activeGrant($second->userId));
        $this->deny(fn () => $this->access->activate($unused->token, '150'));
        $this->assertSame('cancelled', DB::table('notification_outbox')->find($outbox)->status);
        $this->assertSame(0, DB::table('outbox_authorizations')->count());
        $this->assertSame(2, DB::table('users')->count());
        $this->assertSame(1, DB::table('transfers')->count());
        $this->assertSame(2, DB::table('user_wallets')->count());
        $fresh = $this->access->issue();
        $newGrant = $this->access->activate($fresh->token, '200');
        $this->assertSame($first->userId, $newGrant->userId);
        $this->assertNotNull($this->access->activeGrant($newGrant->userId));
    }

    public function test_local_commands_validate_inputs_and_do_not_require_user_operator_privileges(): void
    {
        $this->artisan('access:issue', ['--hours' => '0'])->assertFailed();
        $this->assertSame(0, DB::table('access_keys')->count());
        $key = $this->access->issue();
        $this->artisan('access:expire-key', ['id' => (string) $key->id])->assertSuccessful();
        $this->deny(fn () => $this->access->activate($key->token, '160'));
        $this->artisan('access:revoke-key', ['id' => (string) $key->id])->assertSuccessful();
        $this->artisan('access:invalidate-restored')->assertFailed();
        $this->artisan('access:invalidate-restored', ['--force' => true])->assertSuccessful();
    }

    private function deny(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected a denied access operation.');
        } catch (AccessDenied $exception) {
            $this->assertSame('Access is unavailable.', $exception->getMessage());
        }
    }

    private function sharedDelivery(): array
    {
        $first = $this->access->activate($this->access->issue()->token, '200');
        $second = $this->access->activate($this->access->issue()->token, '201');
        $destination = DB::table('chat_destinations')->insertGetId(['chat_id' => '-100200', 'kind' => 'group']);
        $wallet = DB::table('wallets')->insertGetId(['chain' => 'ethereum', 'address' => '0x'.str_repeat('a', 40)]);
        $transfer = DB::table('transfers')->insertGetId([
            'chain' => 'ethereum', 'transaction_hash' => '0x'.str_repeat('b', 64),
            'receipt_log_position' => 0, 'contract_address' => '0x'.str_repeat('c', 40),
            'from_address' => '0x'.str_repeat('d', 40), 'to_address' => '0x'.str_repeat('a', 40),
            'amount_raw' => '1000000', 'block_number' => 10, 'block_hash' => '0x'.str_repeat('e', 64),
            'block_timestamp' => now()->timestamp,
        ]);
        $outbox = DB::table('notification_outbox')->insertGetId(['transfer_id' => $transfer, 'destination_id' => $destination]);
        foreach ([$first, $second] as $activation) {
            DB::table('user_chat_destinations')->insert(['user_id' => $activation->userId, 'destination_id' => $destination]);
            $userWallet = DB::table('user_wallets')->insertGetId([
                'user_id' => $activation->userId, 'wallet_id' => $wallet, 'label' => 'private-'.$activation->userId,
            ]);
            $subscription = DB::table('wallet_subscriptions')->insertGetId([
                'user_id' => $activation->userId, 'user_wallet_id' => $userWallet, 'destination_id' => $destination,
            ]);
            DB::table('outbox_authorizations')->insert([
                'outbox_id' => $outbox, 'user_id' => $activation->userId,
                'subscription_id' => $subscription, 'grant_id' => $activation->grantId,
            ]);
        }

        return [$first, $second, $outbox];
    }
}
