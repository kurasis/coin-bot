<?php

namespace Tests\Feature;

use App\Access\AccessDenied;
use App\Access\AccessService;
use App\Blockchain\Contracts\ChainClient;
use App\Domain\UserScope;
use App\Monitoring\OutboxRouter;
use App\Telegram\OutboxWorker;
use App\Wallets\BackfillService;
use App\Wallets\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SecurityTenantAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config([
            'telegram.allowed_user_ids' => '', 'telegram.token' => '123456:tenant_audit_fixture',
            'telegram.api_url' => 'https://telegram.audit.invalid',
        ]);
    }

    #[DataProvider('deniedWalletCommands')]
    public function test_wallet_cli_ownership_and_access_rejections_leave_all_tables_unchanged(string $operation, string $actorState): void
    {
        [$alice, $bob, $aliceWallet, $bobWallet] = $this->twoOwners();
        if ($actorState === 'revoked') {
            app(AccessService::class)->revokeGrant((int) DB::table('access_grants')->where('user_id', $bob)->value('id'));
        }
        $arguments = ['wallet' => $bobWallet->id];
        if ($actorState !== 'missing') {
            $arguments['--user'] = $actorState === 'other' ? $alice : $bob;
        }
        if ($operation === 'update') {
            $arguments += ['--label' => 'Attacker replacement', '--direction' => 'outgoing', '--min-amount' => '7'];
        } elseif ($operation === 'backfill-create') {
            $arguments += ['from' => '90', '--to' => '110', '--notify' => true];
        }
        $before = $this->snapshot();

        $this->artisan('wallet:'.$operation, $arguments)->assertFailed();

        $this->assertSame($before, $this->snapshot());
        $this->assertSame('Alice private', DB::table('user_wallets')->where('id', $aliceWallet->id)->value('label'));
        $this->assertSame('Bob private', DB::table('user_wallets')->where('id', $bobWallet->id)->value('label'));
        Http::assertNothingSent();
    }

    public static function deniedWalletCommands(): array
    {
        $cases = [];
        foreach (['update', 'disable', 'backfill-create'] as $operation) {
            foreach (['other', 'missing', 'revoked'] as $state) {
                $cases[$operation.' '.$state] = [$operation, $state];
            }
        }

        return $cases;
    }

    public function test_allowed_operator_commands_modify_only_the_selected_owners_records(): void
    {
        [$alice, $bob, $aliceWallet, $bobWallet] = $this->twoOwners();
        $aliceBefore = app(UserScope::class)->wallets($alice)->toJson();
        $aliceSubscriptions = DB::table('wallet_subscriptions')->where('user_id', $alice)->get()->toJson();

        $this->artisan('wallet:update', ['wallet' => $bobWallet->id, '--user' => $bob, '--label' => 'Updated Bob', '--direction' => 'incoming'])->assertSuccessful();
        $this->artisan('wallet:backfill-create', ['wallet' => $bobWallet->id, 'from' => '90', '--to' => '110', '--user' => $bob, '--notify' => true])->assertSuccessful();
        $this->artisan('wallet:disable', ['wallet' => $bobWallet->id, '--user' => $bob])->assertSuccessful();

        $this->assertSame($aliceBefore, app(UserScope::class)->wallets($alice)->toJson());
        $this->assertSame($aliceSubscriptions, DB::table('wallet_subscriptions')->where('user_id', $alice)->get()->toJson());
        $this->assertDatabaseHas('user_wallets', ['id' => $bobWallet->id, 'label' => 'Updated Bob', 'enabled' => false, 'start_block' => 90]);
        $this->assertDatabaseHas('backfill_jobs', ['user_id' => $bob, 'user_wallet_id' => $bobWallet->id, 'notify' => true]);
        $this->assertDatabaseMissing('backfill_jobs', ['user_id' => $alice]);
        $this->assertSame('Alice private', app(UserScope::class)->wallet($alice, (int) $aliceWallet->id)->label);
        Http::assertNothingSent();
    }

    #[DataProvider('protectedImportFields')]
    public function test_structured_import_cannot_assign_ownership_or_routing_fields(string $field): void
    {
        [$alice, $bob] = $this->twoOwners();
        $file = tempnam(sys_get_temp_dir(), 'tenant-audit-');
        file_put_contents($file, json_encode([
            ['chain' => 'ethereum', 'address' => $this->address(3), 'start_block' => 100],
            ['chain' => 'ethereum', 'address' => $this->address(4), 'start_block' => 100, $field => $bob],
        ], JSON_THROW_ON_ERROR));
        $before = $this->snapshot();
        try {
            $this->artisan('wallet:import', ['file' => $file, '--user' => $alice, '--format' => 'json'])->assertFailed();
            $this->assertSame($before, $this->snapshot());
            Http::assertNothingSent();
        } finally {
            unlink($file);
        }
    }

    public static function protectedImportFields(): array
    {
        return array_map(fn (string $field): array => [$field], [
            'user_id', 'wallet_id', 'destination_id', 'grant_id', 'subscription_id',
            'notification_start_block', 'role', 'enabled',
        ]);
    }

    public function test_a_foreign_backfill_job_id_cannot_be_run_or_changed_before_external_work(): void
    {
        [$alice, $bob, , $bobWallet] = $this->twoOwners();
        $job = app(BackfillService::class)->create($bob, (int) $bobWallet->id, 90, 110, true);
        $client = $this->unusedClient();
        $before = $this->snapshot();

        try {
            app(BackfillService::class)->run($alice, (int) $job->id, $client);
            $this->fail('Another user must not run a private backfill job.');
        } catch (InvalidArgumentException) {
            $this->assertSame($before, $this->snapshot());
            $this->assertSame(0, $client->calls);
            Http::assertNothingSent();
        }
    }

    public function test_grant_revocation_denies_private_wallet_listing_without_data_or_mutations(): void
    {
        [$alice, $bob] = $this->twoOwners();
        $grantId = (int) DB::table('access_grants')->where('user_id', $alice)->value('id');
        app(AccessService::class)->revokeGrant($grantId);
        $before = $this->snapshot();

        try {
            app(WalletService::class)->wallets($alice);
            $this->fail('Revoked grants must not authorize wallet listing.');
        } catch (AccessDenied) {
            $this->assertSame($before, $this->snapshot());
            $this->assertSame('Bob private', app(WalletService::class)->wallets($bob)->sole()->label);
            Http::assertNothingSent();
        }
    }

    public function test_revalidation_rejects_forged_destination_links_and_authorizations(): void
    {
        [$alice, $bob, $aliceWallet, $bobWallet] = $this->twoOwners(true);
        $transferId = $this->transfer();
        app(OutboxRouter::class)->enqueue($transferId);
        $aliceDestination = (int) DB::table('wallet_subscriptions')->where('user_id', $alice)->value('destination_id');
        $bobDestination = (int) DB::table('wallet_subscriptions')->where('user_id', $bob)->value('destination_id');
        $aliceOutbox = (int) DB::table('notification_outbox')->where('destination_id', $aliceDestination)->value('id');
        $bobOutbox = (int) DB::table('notification_outbox')->where('destination_id', $bobDestination)->value('id');

        // Synthetic inconsistent references simulate stale/imported data. No user-facing
        // endpoint is claimed to allow these direct database mutations.
        $bobAuthorization = DB::table('outbox_authorizations')->where('outbox_id', $bobOutbox)->sole();
        DB::table('outbox_authorizations')->where('outbox_id', $aliceOutbox)->delete();
        DB::table('outbox_authorizations')->insert([
            'outbox_id' => $aliceOutbox, 'user_id' => $bob,
            'subscription_id' => $bobAuthorization->subscription_id, 'grant_id' => $bobAuthorization->grant_id,
        ]);
        $this->assertCount(0, app(OutboxRouter::class)->authorizedRoutes($aliceOutbox));
        $this->assertCount(1, app(OutboxRouter::class)->authorizedRoutes($bobOutbox));
        $sent = [];
        Http::fake(['https://telegram.audit.invalid/bot123456:tenant_audit_fixture/sendMessage' => function ($request) use (&$sent) {
            $sent[] = $request->data();

            return Http::response(['ok' => true, 'result' => ['message_id' => 41, 'chat' => ['id' => (int) $request['chat_id']]]]);
        }]);
        app(OutboxWorker::class)->runOnce();
        app(OutboxWorker::class)->runOnce();

        $this->assertDatabaseHas('notification_outbox', ['id' => $aliceOutbox, 'status' => 'cancelled']);
        $this->assertDatabaseHas('notification_outbox', ['id' => $bobOutbox, 'status' => 'sent']);
        $this->assertCount(1, $sent);
        $this->assertSame('910002', $sent[0]['chat_id']);
        $this->assertStringContainsString('Bob private', $sent[0]['text']);
        $this->assertStringNotContainsString('Alice private', $sent[0]['text']);
        $this->assertArrayNotHasKey('parse_mode', $sent[0]);
    }

    private function twoOwners(bool $sharedAddress = false): array
    {
        $access = app(AccessService::class);
        $alice = $access->activate($access->issue()->token, '910001')->userId;
        $bob = $access->activate($access->issue()->token, '910002')->userId;
        $aliceWallet = app(WalletService::class)->add($alice, 'ethereum', $this->address(1), 'Alice private', 100);
        $bobWallet = app(WalletService::class)->add($bob, 'ethereum', $this->address($sharedAddress ? 1 : 2), 'Bob private', 100);
        DB::table('scan_cursors')->where('role', 'primary')->update(['last_block' => 110]);

        return [$alice, $bob, $aliceWallet, $bobWallet];
    }

    private function snapshot(): string
    {
        $tables = DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        $snapshot = [];
        foreach ($tables as $table) {
            $rows = DB::table($table->name)->get()->map(fn (object $row): array => (array) $row)->all();
            usort($rows, fn (array $left, array $right): int => strcmp(json_encode($left), json_encode($right)));
            $snapshot[$table->name] = $rows;
        }

        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    private function address(int $number): string
    {
        return '0x'.str_pad(dechex($number), 40, '0', STR_PAD_LEFT);
    }

    private function transfer(): int
    {
        return DB::table('transfers')->insertGetId([
            'chain' => 'ethereum', 'transaction_hash' => '0x'.str_repeat('a', 64), 'receipt_log_position' => 0,
            'contract_address' => '0xdac17f958d2ee523a2206206994597c13d831ec7',
            'from_address' => $this->address(9), 'to_address' => $this->address(1), 'amount_raw' => '1000000',
            'block_number' => 111, 'block_hash' => '0x'.str_repeat('b', 64), 'block_timestamp' => 1793038000,
        ]);
    }

    private function unusedClient(): ChainClient
    {
        return new class implements ChainClient
        {
            public int $calls = 0;

            public function chain(): string
            {
                $this->calls++;

                return 'ethereum';
            }

            public function provider(): string
            {
                $this->calls++;

                return 'primary';
            }

            public function verifyNetwork(): void
            {
                $this->calls++;
            }

            public function finalizedBlock(): array
            {
                $this->calls++;

                return [];
            }

            public function block(int $number): array
            {
                $this->calls++;

                return [];
            }

            public function logs(int $from, int $to, array $normalizedWatchedAddresses, string $direction): array
            {
                $this->calls++;

                return [];
            }

            public function receipt(string $transactionHash): array
            {
                $this->calls++;

                return [];
            }
        };
    }
}
