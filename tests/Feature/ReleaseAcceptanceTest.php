<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Access\AccessService;
use App\Access\ActivationResult;
use App\Blockchain\Exceptions\RpcException;
use App\Domain\UserScope;
use App\Monitoring\BlockScanner;
use App\Monitoring\OutboxRouter;
use App\Telegram\OutboxWorker;
use App\Wallets\BackfillService;
use App\Wallets\WalletService;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ReleaseChainClient;
use Tests\TestCase;

class ReleaseAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private array $acceptedMessages = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config([
            'telegram.token' => '123456:'.str_repeat('test', 8),
            'operations.state_directory' => sys_get_temp_dir().'/coin-bot-release-'.bin2hex(random_bytes(8)),
        ]);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (! str_ends_with($request->url(), '/sendMessage')) {
                $this->fail('Release acceptance permits only simulated Telegram delivery.');
            }
            $data = $request->data();
            $this->acceptedMessages[] = ['chat_id' => (string) $data['chat_id'], 'text' => $data['text']];

            return Http::response(['ok' => true, 'result' => ['message_id' => 1000 + count($this->acceptedMessages), 'chat' => ['id' => $data['chat_id']]]]);
        });
    }

    public function test_24_hour_backlog_for_100_addresses_resumes_and_delivers_isolated_notifications(): void
    {
        $alice = $this->enroll('9001');
        $bob = $this->enroll('9002');
        $wallets = app(WalletService::class);
        $wallets->importBatch($alice->userId, $this->walletRecords('tron', range(1, 50), 'alice-private-', 1));
        $wallets->importBatch($bob->userId, array_merge(
            $this->walletRecords('tron', range(51, 100), 'bob-private-', 1),
            [['chain' => 'tron', 'address' => ReleaseChainClient::hexAddress(1), 'label' => 'bob-private-shared', 'start_block' => 1]],
        ));
        $this->assertDatabaseCount('wallets', 100);
        $this->assertDatabaseCount('user_wallets', 101);
        $this->assertCount(50, $wallets->wallets($alice->userId));
        $this->assertCount(51, $wallets->wallets($bob->userId));

        $events = [];
        foreach (range(1, 100) as $number) {
            $events[] = [
                'height' => $number * 288,
                'from' => ReleaseChainClient::hexAddress($number === 50 ? 50 : 500),
                'to' => ReleaseChainClient::hexAddress($number), 'amount' => $number * 1000000,
                'copies' => $number % 25 === 0 ? 2 : 1,
            ];
        }
        $client = new ReleaseChainClient('tron', 28800, $events);
        $scanner = app(BlockScanner::class);
        for ($chunk = 0; $chunk < 10; $chunk++) {
            $scanner->scan($client, 1000);
        }
        $this->assertSame(10000, $this->lastBlock('tron'));
        $this->assertDatabaseCount('transfers', 35);
        $this->assertDatabaseCount('notification_outbox', 36);

        $client->failOnceAtFrom = 10001;
        try {
            $scanner->scan($client, 1000);
            $this->fail('The injected incomplete range must remain unfinished.');
        } catch (RpcException $error) {
            $this->assertTrue($error->retryable);
        }
        $this->assertSame(10000, $this->lastBlock('tron'));
        $this->assertDatabaseCount('scan_ranges', 10);
        $this->assertDatabaseCount('transfers', 35);

        // Restart both services from database state, without retaining an in-memory cursor.
        $resumed = new ReleaseChainClient('tron', 28800, $events);
        $scanner = app(BlockScanner::class);
        for ($chunk = 0; $chunk < 19; $chunk++) {
            $scanner->scan($resumed, 1000);
        }
        $this->assertSame(10001, min(array_column($resumed->requests, 'from')));
        $this->assertSame(28800, $this->lastBlock('tron'));
        $this->assertDatabaseCount('scan_ranges', 29);
        $this->assertSame(28800, (int) DB::table('scan_ranges')->selectRaw('SUM(to_block - from_block + 1) as blocks')->value('blocks'));
        $this->assertDatabaseCount('transfers', 104);
        $sum = DB::table('transfers')->pluck('amount_raw')->reduce(static fn (string $total, string $amount): string => bcadd($total, $amount, 0), '0');
        $this->assertSame('5300000000', $sum);
        $this->assertDatabaseCount('notification_outbox', 105);
        $this->assertSame(52, $this->outboxFor('9001')->count());
        $this->assertSame(53, $this->outboxFor('9002')->count());
        $this->assertCount(52, app(UserScope::class)->transferHistory($alice->userId));
        $this->assertCount(53, app(UserScope::class)->transferHistory($bob->userId));
        $this->assertTrue($scanner->scan($resumed, 1000)->idle);
        $this->assertDatabaseCount('notification_outbox', 105);

        $this->drainOutbox();
        $this->assertSame(105, DB::table('notification_outbox')->where('status', 'sent')->count());
        Http::assertSentCount(105);
        $aliceMessages = array_values(array_filter($this->acceptedMessages, static fn (array $message): bool => $message['chat_id'] === '9001'));
        $bobMessages = array_values(array_filter($this->acceptedMessages, static fn (array $message): bool => $message['chat_id'] === '9002'));
        $this->assertCount(52, $aliceMessages);
        $this->assertCount(53, $bobMessages);
        foreach ($aliceMessages as $message) {
            $this->assertStringContainsString('alice-private-', $message['text']);
            $this->assertStringNotContainsString('bob-private-', $message['text']);
        }
        foreach ($bobMessages as $message) {
            $this->assertStringContainsString('bob-private-', $message['text']);
            $this->assertStringNotContainsString('alice-private-', $message['text']);
        }
        $aliceShared = array_values(array_filter($aliceMessages, static fn (array $message): bool => str_contains($message['text'], 'alice-private-001')))[0];
        $bobShared = array_values(array_filter($bobMessages, static fn (array $message): bool => str_contains($message['text'], 'bob-private-shared')))[0];
        preg_match('/Event: ([a-f0-9]{16})/', $aliceShared['text'], $aliceEvent);
        preg_match('/Event: ([a-f0-9]{16})/', $bobShared['text'], $bobEvent);
        $this->assertSame($aliceEvent[1], $bobEvent[1]);
    }

    public function test_commit_failure_ambiguous_delivery_and_reenrollment_preserve_authorized_work(): void
    {
        $access = app(AccessService::class);
        $wallets = app(WalletService::class);
        $alice = $this->enroll('9101');
        $bob = $this->enroll('9102');
        $aliceWallet = $wallets->add($alice->userId, 'ethereum', ReleaseChainClient::hexAddress(1), 'alice-private-treasury', 100);
        $wallets->add($bob->userId, 'ethereum', ReleaseChainClient::hexAddress(1), 'bob-private-reserve', 100);
        $events = [$this->sharedEvent(110, 1000000), $this->sharedEvent(120, 2000000)];
        $client = new ReleaseChainClient('ethereum', 120, $events);
        DB::unprepared("CREATE TRIGGER release_reject_enqueue BEFORE INSERT ON notification_outbox BEGIN SELECT RAISE(ABORT, 'Injected outbox failure'); END");
        try {
            app(BlockScanner::class)->scan($client, 1000);
            $this->fail('The database trigger must interrupt the entire range commit.');
        } catch (QueryException) {
            $this->assertSame(99, $this->lastBlock('ethereum'));
            $this->assertDatabaseCount('transfers', 0);
            $this->assertDatabaseCount('notification_outbox', 0);
            $this->assertDatabaseCount('scan_ranges', 0);
        } finally {
            DB::unprepared('DROP TRIGGER release_reject_enqueue');
        }
        app(BlockScanner::class)->scan(new ReleaseChainClient('ethereum', 120, $events), 1000);
        $this->assertSame(120, $this->lastBlock('ethereum'));
        $this->assertDatabaseCount('transfers', 2);
        $this->assertDatabaseCount('notification_outbox', 4);

        $access->revokeGrant($alice->grantId);
        $this->assertSame(2, $this->outboxFor('9101')->where('o.status', 'cancelled')->count());
        $this->assertSame(2, $this->outboxFor('9102')->where('o.status', 'pending')->count());
        DB::unprepared("CREATE TRIGGER release_reject_ack BEFORE UPDATE ON notification_outbox WHEN NEW.status = 'sent' BEGIN SELECT RAISE(ABORT, 'Injected delivery acknowledgment failure'); END");
        try {
            app(OutboxWorker::class)->runOnce();
            $this->fail('Telegram acceptance followed by lost local acknowledgment must leave a recoverable lease.');
        } catch (QueryException) {
            $processing = DB::table('notification_outbox')->where('status', 'processing')->sole();
            $this->assertNull($processing->telegram_message_id);
            $this->assertSame(1, count($this->acceptedMessages));
        } finally {
            DB::unprepared('DROP TRIGGER release_reject_ack');
        }
        $this->travel(120)->seconds();
        app(OutboxWorker::class)->runOnce();
        $this->assertCount(2, $this->acceptedMessages);
        $this->assertSame($this->acceptedMessages[0], $this->acceptedMessages[1]);
        $this->assertSame(2, DB::table('notification_outbox')->where('status', 'sent')->sole()->attempts);

        $reenrolled = $access->activate($access->issue()->token, '9101', 3);
        $this->assertSame($alice->userId, $reenrolled->userId);
        $this->assertNotSame($alice->grantId, $reenrolled->grantId);
        $wallets->refreshWatchSetsForUser($reenrolled->userId);
        $this->assertSame('alice-private-treasury', $wallets->wallets($alice->userId)->sole()->label);
        $this->assertSame(121, DB::table('wallet_subscriptions')->where('user_id', $alice->userId)->value('notification_start_block'));

        // A silent historical repair may reveal a provider omission, but cannot lower Alice's delivery floor.
        $repairEvents = [...$events, $this->sharedEvent(115, 7000000)];
        $backfill = app(BackfillService::class)->create($alice->userId, (int) $aliceWallet->id, 100, 120, false);
        $repair = app(BackfillService::class)->run($alice->userId, (int) $backfill->id, new ReleaseChainClient('ethereum', 120, $repairEvents), 1000);
        $this->assertSame('completed', $repair->status);
        $this->assertDatabaseCount('transfers', 3);
        $omitted = DB::table('transfers')->where('block_number', 115)->sole();
        DB::transaction(fn () => app(OutboxRouter::class)->enqueue((int) $omitted->id));
        $this->assertDatabaseCount('notification_outbox', 5);
        $this->assertSame(2, $this->outboxFor('9101')->where('o.status', 'cancelled')->count());
        $this->assertSame(0, $this->outboxFor('9101')->whereIn('o.status', ['pending', 'processing', 'retry'])->count());

        $future = new ReleaseChainClient('ethereum', 140, [...$repairEvents, $this->sharedEvent(130, 3000000)]);
        app(BlockScanner::class)->scan($future, 1000);
        $this->assertDatabaseCount('transfers', 4);
        $this->assertDatabaseCount('notification_outbox', 7);
        $this->drainOutbox();
        $this->assertSame(5, DB::table('notification_outbox')->where('status', 'sent')->count());
        $this->assertSame(2, DB::table('notification_outbox')->where('status', 'cancelled')->count());
        Http::assertSentCount(6); // Five durable deliveries, plus the deliberately ambiguous first acceptance.
        $aliceMessages = array_values(array_filter($this->acceptedMessages, static fn (array $message): bool => $message['chat_id'] === '9101'));
        $this->assertCount(1, $aliceMessages);
        $this->assertStringContainsString('Amount: 3.000000 USDT', $aliceMessages[0]['text']);
        $this->assertStringNotContainsString('bob-private-reserve', $aliceMessages[0]['text']);
    }

    private function enroll(string $telegramId): ActivationResult
    {
        $access = app(AccessService::class);

        return $access->activate($access->issue()->token, $telegramId, $telegramId === '9001' || $telegramId === '9101' ? 1 : 2);
    }

    private function walletRecords(string $chain, array $numbers, string $labelPrefix, int $start): array
    {
        return array_map(static fn (int $number): array => [
            'chain' => $chain, 'address' => ReleaseChainClient::hexAddress($number),
            'label' => $labelPrefix.str_pad((string) $number, 3, '0', STR_PAD_LEFT), 'start_block' => $start,
        ], $numbers);
    }

    private function lastBlock(string $chain): int
    {
        return (int) DB::table('scan_cursors')->where('chain', $chain)->where('role', 'primary')->value('last_block');
    }

    private function outboxFor(string $chatId): Builder
    {
        return DB::table('notification_outbox as o')->join('chat_destinations as d', 'd.id', 'o.destination_id')->where('d.chat_id', $chatId);
    }

    private function sharedEvent(int $height, int $amount): array
    {
        return ['height' => $height, 'from' => ReleaseChainClient::hexAddress(500), 'to' => ReleaseChainClient::hexAddress(1), 'amount' => $amount];
    }

    private function drainOutbox(): void
    {
        $worker = app(OutboxWorker::class);
        for ($attempt = 0; $attempt < 300; $attempt++) {
            if (! $worker->runOnce()) {
                return;
            }
            $this->travel(1)->seconds();
        }
        $this->fail('The bounded acceptance workload did not drain.');
    }
}
