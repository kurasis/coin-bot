<?php

namespace Tests\Feature;

use App\Access\AccessDenied;
use App\Access\AccessService;
use App\Blockchain\AddressCodec;
use App\Blockchain\Contracts\ChainClient;
use App\Blockchain\EthereumRpcClient;
use App\Monitoring\BlockScanner;
use App\Monitoring\OutboxRouter;
use App\Monitoring\ScanException;
use App\Operations\MaintenanceGuard;
use App\Wallets\BackfillService;
use App\Wallets\WalletService;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class WalletManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_users_share_scanning_without_sharing_labels_or_destinations(): void
    {
        $alice = $this->user('1001');
        $bob = $this->user('1002');
        $wallets = app(WalletService::class);
        $a = $wallets->add($alice, 'ethereum', $this->address(1), 'Alice private', 100);
        $b = $wallets->add($bob, 'ethereum', $this->address(1), 'Bob private', 100, 'incoming', '2500000');
        $this->assertSame($a->wallet_id, $b->wallet_id);
        $this->assertSame('Alice private', $wallets->wallets($alice)->sole()->label);
        $this->assertSame('Bob private', $wallets->wallets($bob)->sole()->label);
        $this->assertDatabaseCount('wallets', 1);
        $this->assertDatabaseCount('chat_destinations', 2);
        $this->assertSame(1, DB::table('watch_set_wallets')->where('watch_set_id', $this->cursor()->watch_set_id)->count());
        $this->assertSame('2500000', DB::table('wallet_subscriptions')->where('user_id', $bob)->value('min_amount'));
    }

    public function test_scoped_update_rejects_another_users_wallet(): void
    {
        $alice = $this->user('1001');
        $bob = $this->user('1002');
        $wallets = app(WalletService::class);
        $owned = $wallets->add($bob, 'ethereum', $this->address(1), 'Bob private', 100);
        try {
            $wallets->update($alice, (int) $owned->id, ['label' => 'Intrusion']);
            $this->fail('A user cannot update another owner\'s private wallet.');
        } catch (ModelNotFoundException) {
            $this->assertSame('Bob private', $wallets->wallets($bob)->sole()->label);
        }
    }

    public function test_wallet_operations_require_an_active_grant(): void
    {
        $user = $this->user('1001');
        DB::table('access_grants')->where('user_id', $user)->update(['revoked_at' => now()]);
        $this->expectException(AccessDenied::class);
        app(WalletService::class)->add($user, 'ethereum', $this->address(1), startBlock: 100);
    }

    public function test_invalid_record_rejects_entire_batch_before_any_write(): void
    {
        $user = $this->user('1001');
        try {
            app(WalletService::class)->importBatch($user, [
                ['chain' => 'ethereum', 'address' => $this->address(1), 'start_block' => 100],
                ['chain' => 'ethereum', 'address' => 'bad address', 'start_block' => 100],
            ]);
            $this->fail('Expected complete batch validation.');
        } catch (\Throwable) {
            $this->assertDatabaseCount('wallets', 0);
            $this->assertDatabaseCount('user_wallets', 0);
            $this->assertDatabaseCount('scan_cursors', 0);
        }
    }

    public function test_100_wallet_import_is_repeatable_and_preserves_private_settings(): void
    {
        $user = $this->user('1001');
        $wallets = app(WalletService::class);
        $records = [];
        for ($index = 1; $index <= 100; $index++) {
            $records[] = ['chain' => 'ethereum', 'address' => $this->address($index), 'label' => 'Wallet '.$index, 'start_block' => 100];
        }
        $result = $wallets->importBatch($user, $records);
        $wallets->update($user, (int) $result->first()->id, ['label' => 'Keep this private label', 'min_amount' => '1000000']);
        $wallets->importBatch($user, $records);
        $this->assertDatabaseCount('wallets', 100);
        $this->assertDatabaseCount('user_wallets', 100);
        $this->assertSame('Keep this private label', $wallets->wallets($user)->first()->label);
        $this->assertSame('1000000', DB::table('wallet_subscriptions')->where('user_wallet_id', $result->first()->id)->value('min_amount'));
        $this->assertSame(100, DB::table('watch_set_wallets')->where('watch_set_id', $this->cursor()->watch_set_id)->count());
        $this->assertSame(99, $this->cursor()->last_block);
    }

    public function test_cutover_preserves_old_snapshot_and_monitoring_for_other_owner(): void
    {
        $alice = $this->user('1001');
        $bob = $this->user('1002');
        $wallets = app(WalletService::class);
        $a = $wallets->add($alice, 'ethereum', $this->address(1), startBlock: 100);
        $b = $wallets->add($bob, 'ethereum', $this->address(1), startBlock: 100);
        $oldSet = $this->cursor()->watch_set_id;
        DB::table('scan_cursors')->where('id', $this->cursor()->id)->update(['last_block' => 110]);
        $wallets->disable($alice, (int) $a->id);
        $this->assertSame(110, $this->cursor()->last_block);
        $this->assertNotSame($oldSet, $this->cursor()->watch_set_id);
        $this->assertSame(110, DB::table('watch_sets')->where('id', $this->cursor()->watch_set_id)->value('cutover_block'));
        $this->assertSame(1, DB::table('watch_set_wallets')->where('watch_set_id', $oldSet)->count());
        $this->assertSame(1, DB::table('watch_set_wallets')->where('watch_set_id', $this->cursor()->watch_set_id)->count());
        $wallets->disable($bob, (int) $b->id);
        $this->assertSame(0, DB::table('watch_set_wallets')->where('watch_set_id', $this->cursor()->watch_set_id)->count());
    }

    public function test_in_flight_scan_cannot_commit_across_a_wallet_cutover(): void
    {
        $user = $this->user('1001');
        $wallets = app(WalletService::class);
        $wallets->add($user, 'ethereum', $this->address(1), startBlock: 100);
        $client = $this->client();
        $client->onLogs = fn () => $wallets->add($user, 'ethereum', $this->address(2), startBlock: 100);
        try {
            app(BlockScanner::class)->scan($client, 10);
            $this->fail('The old snapshot must not close after a concurrent cutover.');
        } catch (ScanException) {
            $this->assertSame(99, $this->cursor()->last_block);
            $this->assertDatabaseCount('scan_ranges', 0);
        }
        app(BlockScanner::class)->scan($client, 10);
        $this->assertSame(109, $this->cursor()->last_block);
        $this->assertSame(2, DB::table('watch_set_wallets')->where('watch_set_id', $this->cursor()->watch_set_id)->count());
    }

    public function test_new_wallet_with_explicit_past_start_gets_silent_backfill_only_through_cutover(): void
    {
        $user = $this->user('1001');
        $wallets = app(WalletService::class);
        $wallets->add($user, 'ethereum', $this->address(1), startBlock: 100);
        DB::table('scan_cursors')->where('id', $this->cursor()->id)->update(['last_block' => 110]);
        $new = $wallets->add($user, 'ethereum', $this->address(2), startBlock: 90);
        $job = DB::table('backfill_jobs')->sole();
        $this->assertSame($new->id, $job->user_wallet_id);
        $this->assertSame(90, $job->from_block);
        $this->assertSame(110, $job->to_block);
        $this->assertFalse((bool) $job->notify);
        $this->assertSame(110, $this->cursor()->last_block);
        $backfillCursor = DB::table('scan_cursors')->find($job->cursor_id);
        $this->assertSame(89, $backfillCursor->last_block);
        $this->assertNull(DB::table('watch_sets')->where('id', $backfillCursor->watch_set_id)->value('activated_at'));
    }

    public function test_backfill_resumes_after_service_restart_and_does_not_rewind_live_cursor(): void
    {
        $user = $this->user('1001');
        $wallets = app(WalletService::class);
        $wallet = $wallets->add($user, 'ethereum', $this->address(1), startBlock: 100);
        DB::table('scan_cursors')->where('id', $this->cursor()->id)->update(['last_block' => 110]);
        $job = app(BackfillService::class)->create($user, (int) $wallet->id, 100, 110);
        $client = $this->client(true);
        app(BackfillService::class)->run($user, (int) $job->id, $client, 3);
        $this->assertSame(102, DB::table('scan_cursors')->find($job->cursor_id)->last_block);
        app()->forgetInstance(BackfillService::class);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $result = app(BackfillService::class)->run($user, (int) $job->id, $client, 3);
        }
        $this->assertSame('completed', $result->status);
        $this->assertSame(110, DB::table('scan_cursors')->find($job->cursor_id)->last_block);
        $this->assertSame(110, $this->cursor()->last_block);
        $this->assertDatabaseCount('transfers', 1);
        $this->assertDatabaseCount('notification_outbox', 0);
        $again = app(BackfillService::class)->create($user, (int) $wallet->id, 100, 110);
        $this->assertSame($job->id, $again->id);
    }

    public function test_explicit_history_notifications_are_scoped_to_requesting_owner_of_shared_wallet(): void
    {
        $alice = $this->user('1001');
        $bob = $this->user('1002');
        $wallets = app(WalletService::class);
        $wallets->add($alice, 'ethereum', $this->address(1), 'Alice secret label', 100);
        DB::table('scan_cursors')->where('id', $this->cursor()->id)->update(['last_block' => 110]);
        $bobWallet = $wallets->add($bob, 'ethereum', $this->address(1), 'Bob secret label', 100);
        $job = app(BackfillService::class)->create($bob, (int) $bobWallet->id, 100, 110, true);
        app(BackfillService::class)->run($bob, (int) $job->id, $this->client(true));
        $this->assertDatabaseCount('transfers', 1);
        $this->assertDatabaseCount('notification_outbox', 1);
        $this->assertSame([$bob], DB::table('outbox_authorizations')->pluck('user_id')->all());
        $outbox = DB::table('notification_outbox')->sole();
        $this->assertSame('1002', DB::table('chat_destinations')->where('id', $outbox->destination_id)->value('chat_id'));
    }

    public function test_default_start_uses_next_finalized_block_without_importing_old_activity(): void
    {
        $user = $this->user('1001');
        config()->set('blockchain.chains.ethereum.providers.primary.rpc_url', 'https://wallet-fixture.invalid');
        Http::fake(fn ($request) => Http::response(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => $request['method'] === 'eth_chainId'
            ? '0x1' : ['number' => '0xc8', 'hash' => $this->hash(200), 'timestamp' => '0x6553f100']]));
        $wallet = app(WalletService::class)->add($user, 'ethereum', $this->address(1));
        $this->assertSame(201, $wallet->start_block);
        $this->assertSame(200, $this->cursor()->last_block);
        $this->assertDatabaseCount('backfill_jobs', 0);
    }

    public function test_console_import_rejects_unknown_ownership_fields_without_partial_writes(): void
    {
        $user = $this->user('1001');
        $file = tempnam(sys_get_temp_dir(), 'wallet-import-');
        file_put_contents($file, json_encode([
            ['chain' => 'ethereum', 'address' => $this->address(1), 'start_block' => 100],
            ['chain' => 'ethereum', 'address' => $this->address(2), 'start_block' => 100, 'user_id' => 999],
        ], JSON_THROW_ON_ERROR));
        try {
            $this->artisan('wallet:import', ['file' => $file, '--user' => (string) $user])->assertFailed();
            $this->assertDatabaseCount('wallets', 0);
            $this->assertDatabaseCount('user_wallets', 0);
        } finally {
            unlink($file);
        }
    }

    public function test_console_operations_require_explicit_user_and_reject_cross_user_disable(): void
    {
        $alice = $this->user('1001');
        $bob = $this->user('1002');
        $wallet = app(WalletService::class)->add($bob, 'ethereum', $this->address(1), startBlock: 100);
        $this->artisan('wallet:disable', ['wallet' => $wallet->id, '--user' => $alice])->assertFailed();
        $this->artisan('wallet:list')->assertFailed();
        $this->assertTrue((bool) DB::table('user_wallets')->where('id', $wallet->id)->value('enabled'));
    }

    public function test_historical_import_cannot_use_another_owners_wallet_or_future_live_range(): void
    {
        $alice = $this->user('1001');
        $bob = $this->user('1002');
        $wallet = app(WalletService::class)->add($bob, 'ethereum', $this->address(1), startBlock: 100);
        DB::table('scan_cursors')->where('id', $this->cursor()->id)->update(['last_block' => 110]);
        try {
            app(BackfillService::class)->create($alice, (int) $wallet->id, 90, 110);
            $this->fail('Historical imports must respect owner identity.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('backfill_jobs', 0);
        }
        $this->expectException(InvalidArgumentException::class);
        app(BackfillService::class)->create($bob, (int) $wallet->id, 90, 120);
    }

    public function test_revoked_access_pauses_backfill_without_advancing_coverage(): void
    {
        $user = $this->user('1001');
        $wallet = app(WalletService::class)->add($user, 'ethereum', $this->address(1), startBlock: 100);
        DB::table('scan_cursors')->where('id', $this->cursor()->id)->update(['last_block' => 110]);
        $job = app(BackfillService::class)->create($user, (int) $wallet->id, 100, 110, true);
        DB::table('access_grants')->where('user_id', $user)->update(['revoked_at' => now()]);
        try {
            app(BackfillService::class)->run($user, (int) $job->id, $this->client(true));
            $this->fail('A revoked user cannot continue historical delivery.');
        } catch (AccessDenied) {
            $this->assertSame('paused', DB::table('backfill_jobs')->find($job->id)->status);
            $this->assertSame(99, DB::table('scan_cursors')->find($job->cursor_id)->last_block);
            $this->assertDatabaseCount('notification_outbox', 0);
        }
        $access = app(AccessService::class);
        $access->activate($access->issue()->token, '1001');
        $result = app(BackfillService::class)->run($user, (int) $job->id, $this->client(true));
        $this->assertSame('completed', $result->status);
        $this->assertDatabaseCount('notification_outbox', 1);
    }

    public function test_maintenance_blocks_historical_http_and_cursor_changes(): void
    {
        $user = $this->user('1001');
        $wallet = app(WalletService::class)->add($user, 'ethereum', $this->address(1), startBlock: 100);
        DB::table('scan_cursors')->where('id', $this->cursor()->id)->update(['last_block' => 110]);
        $job = app(BackfillService::class)->create($user, (int) $wallet->id, 100, 110);
        $path = sys_get_temp_dir().'/wallet-operations-'.bin2hex(random_bytes(8));
        config()->set('operations.state_directory', $path);
        $guard = app(MaintenanceGuard::class);
        $guard->pause();
        try {
            try {
                app(BackfillService::class)->run($user, (int) $job->id, $this->client(true));
                $this->fail('Maintenance must block historical processing.');
            } catch (\RuntimeException) {
                $this->assertSame(99, DB::table('scan_cursors')->find($job->cursor_id)->last_block);
                $this->assertDatabaseCount('transfers', 0);
            }
        } finally {
            $guard->resume();
            rmdir($path);
        }
    }

    public function test_adding_another_wallet_never_silently_resumes_a_stopped_destination(): void
    {
        $user = $this->user('1001');
        $wallets = app(WalletService::class);
        $wallets->add($user, 'ethereum', $this->address(1), startBlock: 100);
        DB::table('user_chat_destinations')->where('user_id', $user)->update(['enabled' => false, 'revoked_at' => now()]);
        $wallets->add($user, 'ethereum', $this->address(2), startBlock: 100);
        $permission = DB::table('user_chat_destinations')->where('user_id', $user)->sole();
        $this->assertFalse((bool) $permission->enabled);
        $this->assertNotNull($permission->revoked_at);
        $this->assertDatabaseCount('user_wallets', 2);
    }

    public function test_reactivation_refreshes_removed_address_membership_without_rewinding_coverage(): void
    {
        $alice = $this->user('1001');
        $bob = $this->user('1002');
        $wallets = app(WalletService::class);
        $aliceWallet = $wallets->add($alice, 'ethereum', $this->address(1), 'Keep Alice label', 100);
        DB::table('access_grants')->where('user_id', $alice)->update(['revoked_at' => now()]);
        $wallets->add($bob, 'ethereum', $this->address(2), startBlock: 100);
        $this->assertSame(1, DB::table('watch_set_wallets')->where('watch_set_id', $this->cursor()->watch_set_id)->count());
        DB::table('scan_cursors')->where('id', $this->cursor()->id)->update(['last_block' => 110]);
        $access = app(AccessService::class);
        $access->activate($access->issue()->token, '1001');
        $wallets->refreshWatchSetsForUser($alice);
        $this->assertSame(110, $this->cursor()->last_block);
        $this->assertSame(2, DB::table('watch_set_wallets')->where('watch_set_id', $this->cursor()->watch_set_id)->count());
        $this->assertSame('Keep Alice label', $wallets->wallets($alice)->sole()->label);
        $this->assertSame($aliceWallet->id, $wallets->wallets($alice)->sole()->id);
        $this->assertDatabaseCount('backfill_jobs', 0);
    }

    public function test_silent_backfill_never_lowers_notification_floor_during_live_catchup_or_reconciliation(): void
    {
        $alice = $this->user('1001');
        $bob = $this->user('1002');
        $wallets = app(WalletService::class);
        $wallets->add($alice, 'ethereum', $this->address(1), startBlock: 90);
        DB::table('scan_cursors')->where('id', $this->cursor()->id)->update(['last_block' => 99]);
        $bobWallet = $wallets->add($bob, 'ethereum', $this->address(1), startBlock: 110);
        app(BackfillService::class)->create($bob, (int) $bobWallet->id, 90, 99, false);
        $this->assertSame(90, DB::table('user_wallets')->where('id', $bobWallet->id)->value('start_block'));
        $this->assertSame(110, DB::table('wallet_subscriptions')->where('user_wallet_id', $bobWallet->id)->value('notification_start_block'));
        app(BlockScanner::class)->scan($this->client(true), 1);
        $transfer = DB::table('transfers')->sole();
        app(OutboxRouter::class)->enqueue((int) $transfer->id);
        $this->assertDatabaseCount('notification_outbox', 1);
        $this->assertSame([$alice], DB::table('outbox_authorizations')->pluck('user_id')->all());
    }

    public function test_reactivation_floor_blocks_old_reconciliation_but_explicit_history_retains_authorization(): void
    {
        $user = $this->user('1001');
        $wallets = app(WalletService::class);
        $wallet = $wallets->add($user, 'ethereum', $this->address(1), startBlock: 90);
        DB::table('scan_cursors')->where('id', $this->cursor()->id)->update(['last_block' => 110]);
        DB::table('access_grants')->where('user_id', $user)->update(['revoked_at' => now()]);
        $access = app(AccessService::class);
        $access->activate($access->issue()->token, '1001');
        $wallets->refreshWatchSetsForUser($user);
        $this->assertSame(90, DB::table('user_wallets')->where('id', $wallet->id)->value('start_block'));
        $this->assertSame(111, DB::table('wallet_subscriptions')->where('user_wallet_id', $wallet->id)->value('notification_start_block'));
        $silent = app(BackfillService::class)->create($user, (int) $wallet->id, 100, 110);
        app(BackfillService::class)->run($user, (int) $silent->id, $this->client(true));
        $transfer = DB::table('transfers')->sole();
        app(OutboxRouter::class)->enqueue((int) $transfer->id);
        $this->assertDatabaseCount('notification_outbox', 0);
        $explicit = app(BackfillService::class)->create($user, (int) $wallet->id, 100, 110, true);
        app(BackfillService::class)->run($user, (int) $explicit->id, $this->client(true));
        $outbox = DB::table('notification_outbox')->sole();
        $this->assertTrue((bool) DB::table('outbox_authorizations')->sole()->historical_override);
        $routes = app(OutboxRouter::class)->authorizedRoutes((int) $outbox->id);
        $this->assertCount(1, $routes);
        $this->assertSame($user, $routes->sole()->user_id);
    }

    public function test_renewal_while_still_authorized_preserves_pending_delivery_boundary(): void
    {
        $user = $this->user('1001');
        $wallets = app(WalletService::class);
        $wallet = $wallets->add($user, 'ethereum', $this->address(1), startBlock: 100);
        app(BlockScanner::class)->scan($this->client(true), 11);
        $outbox = DB::table('notification_outbox')->sole();
        $access = app(AccessService::class);
        $access->activate($access->issue()->token, '1001');
        $wallets->refreshWatchSetsForUser($user, false);
        $this->assertSame(100, DB::table('wallet_subscriptions')->where('user_wallet_id', $wallet->id)->value('notification_start_block'));
        $this->assertCount(1, app(OutboxRouter::class)->authorizedRoutes((int) $outbox->id));
        $this->assertSame('pending', DB::table('notification_outbox')->find($outbox->id)->status);
    }

    private function user(string $telegramId): int
    {
        $access = app(AccessService::class);

        return $access->activate($access->issue()->token, $telegramId)->userId;
    }

    private function cursor(): object
    {
        return DB::table('scan_cursors')->where('chain', 'ethereum')->where('role', 'primary')->sole();
    }

    private function address(int $number): string
    {
        return '0x'.str_pad(dechex($number), 40, '0', STR_PAD_LEFT);
    }

    private function hash(int $number): string
    {
        return '0x'.str_pad(dechex($number), 64, '0', STR_PAD_LEFT);
    }

    private function client(bool $includeEvent = false): ChainClient
    {
        return new class($includeEvent) implements ChainClient
        {
            public ?Closure $onLogs = null;

            public function __construct(private readonly bool $includeEvent) {}

            public function chain(): string
            {
                return 'ethereum';
            }

            public function provider(): string
            {
                return 'primary';
            }

            public function verifyNetwork(): void {}

            public function finalizedBlock(): array
            {
                return $this->block(200);
            }

            public function block(int $number): array
            {
                return ['number' => $number, 'hash' => '0x'.str_pad(dechex($number), 64, '0', STR_PAD_LEFT), 'timestamp' => 1700000000 + $number];
            }

            public function logs(int $from, int $to, array $normalizedWatchedAddresses, string $direction): array
            {
                if ($this->onLogs !== null) {
                    $callback = $this->onLogs;
                    $this->onLogs = null;
                    $callback();
                }

                return $this->includeEvent && $from <= 100 && $to >= 100 ? [$this->event()] : [];
            }

            public function receipt(string $transactionHash): array
            {
                return ['transactionHash' => $transactionHash, 'status' => '0x1', 'blockNumber' => '0x64',
                    'blockHash' => $this->block(100)['hash'], 'logs' => [$this->event()]];
            }

            private function event(): array
            {
                return ['address' => EthereumRpcClient::USDT_CONTRACT, 'topics' => [EthereumRpcClient::TRANSFER_TOPIC,
                    AddressCodec::topic('ethereum', '0x'.str_pad('2', 40, '0', STR_PAD_LEFT)),
                    AddressCodec::topic('ethereum', '0x'.str_pad('1', 40, '0', STR_PAD_LEFT))],
                    'data' => '0x'.str_pad(dechex(1000000), 64, '0', STR_PAD_LEFT),
                    'blockNumber' => '0x64', 'blockHash' => $this->block(100)['hash'], 'transactionHash' => '0x'.str_repeat('a', 64)];
            }
        };
    }
}
