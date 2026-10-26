<?php

namespace Tests\Feature;

use App\Domain\Amount;
use App\Domain\UserScope;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class DomainPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_uint256_amounts_round_trip_without_float_or_sqlite_integer_conversion(): void
    {
        $amount = Amount::fromHex('0x'.str_repeat('f', 64));
        $this->assertSame(Amount::MAX_UINT256, $amount->raw());
        $this->assertSame('1.000001', (new Amount('1000001'))->format());
        $this->assertSame('0.000000', (new Amount('0000'))->format());
        $this->assertSame('0.000001', (new Amount('1'))->format());
        $this->assertSame(1, (new Amount('1000000'))->compareTo(new Amount('999999')));
        $id = DB::table('transfers')->insertGetId($this->transfer(['amount_raw' => $amount->raw()]));
        $this->assertSame(Amount::MAX_UINT256, DB::table('transfers')->where('id', $id)->value('amount_raw'));
        $this->assertSame('text', DB::selectOne('SELECT typeof(amount_raw) AS stored_type FROM transfers WHERE id = ?', [$id])->stored_type);
    }

    public function test_amounts_reject_values_outside_uint256(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Amount('115792089237316195423570985008687907853269984665640564039457584007913129639936');
    }

    public function test_amounts_reject_float_notation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Amount('1e6');
    }

    public function test_transaction_rolls_back_the_event_when_related_write_fails(): void
    {
        try {
            DB::transaction(function (): void {
                DB::table('transfers')->insert($this->transfer());
                throw new RuntimeException('Simulated failure before range completion.');
            });
            $this->fail('Expected the simulated failure.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('transfers', 0);
        }
    }

    public function test_same_transaction_can_hold_distinct_identical_transfer_logs(): void
    {
        DB::table('transfers')->insert($this->transfer());
        DB::table('transfers')->insert($this->transfer(['receipt_log_position' => 1]));
        $this->assertDatabaseCount('transfers', 2);
        $this->expectException(QueryException::class);
        DB::table('transfers')->insert($this->transfer());
    }

    public function test_outbox_uniqueness_is_per_event_and_destination(): void
    {
        $transfer = DB::table('transfers')->insertGetId($this->transfer());
        $destination = DB::table('chat_destinations')->insertGetId(['chat_id' => '1001']);
        DB::table('notification_outbox')->insert(['transfer_id' => $transfer, 'destination_id' => $destination]);
        $this->expectException(QueryException::class);
        DB::table('notification_outbox')->insert(['transfer_id' => $transfer, 'destination_id' => $destination]);
    }

    public function test_shared_wallet_does_not_share_labels_or_settings(): void
    {
        $alice = DB::table('users')->insertGetId(['telegram_user_id' => '1001']);
        $bob = DB::table('users')->insertGetId(['telegram_user_id' => '1002']);
        $wallet = DB::table('wallets')->insertGetId(['chain' => 'ethereum', 'address' => '0x'.str_repeat('1', 40)]);
        $aliceWallet = DB::table('user_wallets')->insertGetId(['user_id' => $alice, 'wallet_id' => $wallet, 'label' => 'Alice private']);
        $bobWallet = DB::table('user_wallets')->insertGetId(['user_id' => $bob, 'wallet_id' => $wallet, 'label' => 'Bob private']);
        $scope = new UserScope;
        $scope->renameWallet($alice, $aliceWallet, 'Updated Alice');
        $this->assertSame('Updated Alice', $scope->wallets($alice)->sole()->label);
        $this->assertSame('Bob private', $scope->wallets($bob)->sole()->label);
        $this->expectException(ModelNotFoundException::class);
        $scope->wallet($alice, $bobWallet);
    }

    public function test_foreign_keys_reject_a_subscription_to_another_users_wallet(): void
    {
        $alice = DB::table('users')->insertGetId(['telegram_user_id' => '1001']);
        $bob = DB::table('users')->insertGetId(['telegram_user_id' => '1002']);
        $wallet = DB::table('wallets')->insertGetId(['chain' => 'ethereum', 'address' => '0x'.str_repeat('1', 40)]);
        $bobWallet = DB::table('user_wallets')->insertGetId(['user_id' => $bob, 'wallet_id' => $wallet]);
        $destination = DB::table('chat_destinations')->insertGetId(['chat_id' => '1001']);
        DB::table('user_chat_destinations')->insert(['user_id' => $alice, 'destination_id' => $destination]);
        $this->expectException(QueryException::class);
        DB::table('wallet_subscriptions')->insert(['user_id' => $alice, 'user_wallet_id' => $bobWallet, 'destination_id' => $destination]);
    }

    public function test_foreign_keys_reject_a_subscription_to_an_unauthorized_chat(): void
    {
        $alice = DB::table('users')->insertGetId(['telegram_user_id' => '1001']);
        $wallet = DB::table('wallets')->insertGetId(['chain' => 'ethereum', 'address' => '0x'.str_repeat('1', 40)]);
        $aliceWallet = DB::table('user_wallets')->insertGetId(['user_id' => $alice, 'wallet_id' => $wallet]);
        $destination = DB::table('chat_destinations')->insertGetId(['chat_id' => '1002']);
        $this->expectException(QueryException::class);
        DB::table('wallet_subscriptions')->insert(['user_id' => $alice, 'user_wallet_id' => $aliceWallet, 'destination_id' => $destination]);
    }

    public function test_foreign_keys_reject_an_outbox_authorization_using_another_users_grant(): void
    {
        $alice = DB::table('users')->insertGetId(['telegram_user_id' => '1001']);
        $bob = DB::table('users')->insertGetId(['telegram_user_id' => '1002']);
        $wallet = DB::table('wallets')->insertGetId(['chain' => 'ethereum', 'address' => '0x'.str_repeat('1', 40)]);
        $aliceWallet = DB::table('user_wallets')->insertGetId(['user_id' => $alice, 'wallet_id' => $wallet]);
        $destination = DB::table('chat_destinations')->insertGetId(['chat_id' => '1001']);
        DB::table('user_chat_destinations')->insert(['user_id' => $alice, 'destination_id' => $destination]);
        $subscription = DB::table('wallet_subscriptions')->insertGetId(['user_id' => $alice, 'user_wallet_id' => $aliceWallet, 'destination_id' => $destination]);
        $key = DB::table('access_keys')->insertGetId(['token_hash' => str_repeat('a', 64), 'expires_at' => now()->addDay()]);
        $bobGrant = DB::table('access_grants')->insertGetId(['user_id' => $bob, 'access_key_id' => $key]);
        $transfer = DB::table('transfers')->insertGetId($this->transfer());
        $outbox = DB::table('notification_outbox')->insertGetId(['transfer_id' => $transfer, 'destination_id' => $destination]);

        $this->expectException(QueryException::class);
        DB::table('outbox_authorizations')->insert([
            'outbox_id' => $outbox, 'user_id' => $alice, 'subscription_id' => $subscription, 'grant_id' => $bobGrant,
        ]);
    }

    public function test_history_is_scoped_to_owned_addresses_chain_and_start_block(): void
    {
        $alice = DB::table('users')->insertGetId(['telegram_user_id' => '1001']);
        $bob = DB::table('users')->insertGetId(['telegram_user_id' => '1002']);
        $wallet = DB::table('wallets')->insertGetId(['chain' => 'ethereum', 'address' => '0x'.str_repeat('1', 40)]);
        DB::table('user_wallets')->insert(['user_id' => $alice, 'wallet_id' => $wallet, 'start_block' => 10]);
        DB::table('transfers')->insert($this->transfer(['block_number' => 9]));
        DB::table('transfers')->insert($this->transfer(['transaction_hash' => '0x'.str_repeat('b', 64), 'block_number' => 10]));
        DB::table('transfers')->insert($this->transfer(['chain' => 'tron', 'block_number' => 10]));
        $scope = new UserScope;
        $this->assertCount(1, $scope->transferHistory($alice));
        $this->assertSame(10, $scope->transferHistory($alice)->sole()->block_number);
        $this->assertCount(0, $scope->transferHistory($bob));
    }

    private function transfer(array $overrides = []): array
    {
        return array_replace([
            'chain' => 'ethereum',
            'transaction_hash' => '0x'.str_repeat('a', 64),
            'receipt_log_position' => 0,
            'contract_address' => '0xdac17f958d2ee523a2206206994597c13d831ec7',
            'from_address' => '0x'.str_repeat('1', 40),
            'to_address' => '0x'.str_repeat('2', 40),
            'amount_raw' => '1000000',
            'block_number' => 10,
            'block_hash' => '0x'.str_repeat('c', 64),
            'block_timestamp' => 1793030400,
        ], $overrides);
    }
}
