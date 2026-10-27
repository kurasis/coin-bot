<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Access\AccessService;
use App\Blockchain\EthereumRpcClient;
use App\Blockchain\Exceptions\RangeLimitException;
use App\Blockchain\Exceptions\RpcException;
use App\Blockchain\RpcTransport;
use App\Monitoring\ScanException;
use App\Monitoring\TransferDecoder;
use App\Telegram\TelegramClient;
use App\Telegram\TelegramException;
use App\Telegram\TelegramPoller;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\InflateStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SecurityIntegrationAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['telegram.token' => 'synthetic-audit-token']);
    }

    public function test_rpc_over_limit_response_is_rejected_before_envelope_processing(): void
    {
        Http::fake(fn () => Http::response([
            'jsonrpc' => '2.0', 'id' => 1, 'result' => '0x1', 'padding' => str_repeat('x', 8192),
        ]));

        $this->expectException(RangeLimitException::class);
        $this->transport(['max_response_bytes' => 4096])->rpc('eth_chainId', []);
    }

    public function test_telegram_over_limit_response_is_rejected_before_result_processing(): void
    {
        config(['telegram.max_response_bytes' => 4096]);
        Http::fake(fn () => Http::response(['ok' => true, 'result' => [], 'padding' => str_repeat('x', 8192)]));

        $this->expectException(TelegramException::class);
        app(TelegramClient::class)->getUpdates(0);
    }

    public function test_telegram_update_count_does_not_trust_requested_limit(): void
    {
        Http::fake(fn () => Http::response(['ok' => true,
            'result' => array_map(fn (int $id): array => ['update_id' => $id], range(1, 101)),
        ]));

        $this->expectException(TelegramException::class);
        app(TelegramClient::class)->getUpdates(0);
    }

    public function test_excess_telegram_update_batch_cannot_consume_a_key_or_advance_any_state(): void
    {
        $key = app(AccessService::class)->issue();
        Http::fake(fn () => Http::response(['ok' => true, 'result' => array_map(fn (int $id): array => [
            'update_id' => $id, 'message' => [
                'from' => ['id' => 1001, 'is_bot' => false], 'chat' => ['id' => 1001, 'type' => 'private'],
                'text' => $id === 1 ? '/start '.$key->token : '/status',
            ],
        ], range(1, 101))]));
        try {
            app(TelegramPoller::class)->runOnce();
            $this->fail('Oversized update batch must be rejected before processing even its first update.');
        } catch (TelegramException) {
            $this->assertNull(DB::table('access_keys')->value('redeemed_at'));
            foreach (['users', 'access_grants', 'telegram_updates', 'telegram_update_offsets',
                'telegram_replies', 'chat_destinations', 'notification_outbox'] as $table) {
                $this->assertDatabaseCount($table, 0);
            }
        }
        Http::assertSentCount(1);
    }

    #[DataProvider('unsafeEndpoints')]
    public function test_rpc_rejects_unapproved_protocols_and_userinfo_without_any_network(string $url): void
    {
        try {
            $this->transport(['rpc_url' => $url])->rpc('eth_chainId', []);
            $this->fail('Unsafe endpoint configuration must fail before HTTP.');
        } catch (RpcException $exception) {
            $this->assertStringNotContainsString($url, $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        Http::assertNothingSent();
    }

    public static function unsafeEndpoints(): array
    {
        return [
            ['http://127.0.0.1:12345/'], ['file:///tmp/audit-only'],
            ['gopher://127.0.0.1:12345/'], ['https://user:synthetic-password@rpc.audit.test/'],
        ];
    }

    #[DataProvider('invalidEnvelopes')]
    public function test_rpc_envelopes_are_bound_to_the_actual_request(array $body): void
    {
        Http::fake(fn () => Http::response($body));
        $this->expectException(RpcException::class);
        $this->transport()->rpc('eth_chainId', []);
    }

    public static function invalidEnvelopes(): array
    {
        return [
            [['jsonrpc' => '1.0', 'id' => 1, 'result' => '0x1']],
            [['jsonrpc' => '2.0', 'id' => 2, 'result' => '0x1']],
            [['jsonrpc' => '2.0', 'id' => ['unexpected'], 'result' => '0x1']],
            [['jsonrpc' => '2.0', 'id' => 1]],
        ];
    }

    public function test_rpc_rejects_wrong_network_identity_through_the_real_client(): void
    {
        Http::fake(fn () => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x2']));
        $this->expectException(RpcException::class);
        (new EthereumRpcClient($this->transport()))->verifyNetwork();
    }

    public function test_failed_receipt_cannot_become_a_finalized_transfer(): void
    {
        $log = $this->log();
        Http::fake(fn (Request $request) => Http::response([
            'jsonrpc' => '2.0', 'id' => $request['id'], 'result' => [
                'transactionHash' => $log['transactionHash'], 'blockNumber' => '0x64',
                'blockHash' => $log['blockHash'], 'status' => '0x0', 'logs' => [$log],
            ],
        ]));
        $this->expectException(ScanException::class);
        app(TransferDecoder::class)->decode(new EthereumRpcClient($this->transport()), [$log],
            ['0x'.str_repeat('1', 40)], 100, 100);
    }

    public function test_redirect_does_not_forward_rpc_credentials_and_transport_options_disable_it(): void
    {
        Http::fake(function (Request $request, array $options) {
            $this->assertFalse($options['allow_redirects']);

            return Http::response('', 302, ['Location' => 'https://unapproved.audit.test/collect']);
        });
        try {
            $this->transport(['headers' => ['X-Provider-Key' => 'synthetic-provider-value']])->rpc('eth_chainId', []);
            $this->fail('Redirect must be rejected.');
        } catch (RpcException $exception) {
            $this->assertSame(302, $exception->httpStatus);
            $this->assertStringNotContainsString('synthetic-provider-value', (string) $exception);
        }
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'unapproved.audit.test'));
    }

    public function test_telegram_text_is_plain_and_errors_do_not_retain_private_payload_or_token(): void
    {
        $private = '<a href="https://unapproved.audit.test/">Private label</a>';
        Http::fake(function (Request $request, array $options) use ($private) {
            $this->assertSame($private, $request['text']);
            $this->assertArrayNotHasKey('parse_mode', $request->data());
            $this->assertSame(['is_disabled' => true], $request['link_preview_options']);
            $this->assertFalse($options['allow_redirects']);

            return Http::response(['ok' => false, 'error_code' => 400,
                'description' => 'synthetic-audit-token '.$private], 400);
        });
        try {
            app(TelegramClient::class)->sendMessage('1001', $private);
            $this->fail('Telegram failure must not be accepted.');
        } catch (TelegramException $exception) {
            $this->assertStringNotContainsString('synthetic-audit-token', (string) $exception);
            $this->assertStringNotContainsString('Private label', (string) $exception);
            $this->assertNull($exception->getPrevious());
        }
    }

    public function test_rpc_stream_reads_at_most_limit_plus_one_and_closes_without_unbounded_cast(): void
    {
        $state = (object) ['bytes' => 0, 'closed' => false];
        $base = Utils::streamFor(str_repeat('x', 32768));
        $stream = FnStream::decorate($base, [
            'read' => static function (int $length) use ($state, $base): string {
                $chunk = $base->read($length);
                $state->bytes += strlen($chunk);

                return $chunk;
            },
            'getContents' => static fn () => throw new \LogicException('Unbounded response read is prohibited.'),
            '__toString' => static fn () => throw new \LogicException('Unbounded response cast is prohibited.'),
            'close' => static function () use ($state, $base): void {
                $state->closed = true;
                $base->close();
            },
        ]);
        Http::fake(function (Request $request, array $options) use ($stream) {
            $this->assertTrue($options['stream']);
            $this->assertGreaterThan(0, $options['read_timeout']);

            return Http::response($stream);
        });
        try {
            $this->transport(['max_response_bytes' => 4096])->rpc('eth_chainId', []);
            $this->fail('Oversized response must stop reading.');
        } catch (RangeLimitException) {
            $this->assertSame(4097, $state->bytes);
            $this->assertTrue($state->closed);
        }
    }

    public function test_decoded_gzip_bytes_enforce_the_same_budget_and_small_compressed_json_is_accepted(): void
    {
        Http::fake(function (Request $request) {
            $padding = $request->url() === 'https://rpc.audit.test/large' ? str_repeat('x', 8192) : 'small';
            $json = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x1', 'padding' => $padding], JSON_THROW_ON_ERROR);
            $compressed = gzencode($json);

            // Guzzle removes Content-Length after decoding; only decoded bytes count.
            return Http::response(new InflateStream(Utils::streamFor($compressed)), 200,
                ['x-encoded-content-length' => (string) strlen($compressed)]);
        });
        $this->assertSame('0x1', $this->transport(['max_response_bytes' => 4096])->rpc('eth_chainId', []));
        $this->expectException(RangeLimitException::class);
        $this->transport(['rpc_url' => 'https://rpc.audit.test/large', 'max_response_bytes' => 4096])->rpc('eth_chainId', []);
    }

    public function test_truncated_declared_body_is_a_range_limit_and_excessive_json_depth_is_invalid(): void
    {
        Http::fake([
            'https://rpc.audit.test/truncated' => fn () => Http::response(
                ['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x1'], 200, ['Content-Length' => '1000']),
            'https://rpc.audit.test/deep' => fn () => Http::response(str_repeat('[', 70).'0'.str_repeat(']', 70)),
        ]);
        try {
            $this->transport(['rpc_url' => 'https://rpc.audit.test/truncated'])->rpc('eth_chainId', []);
            $this->fail('Declared truncated response must not certify a range.');
        } catch (RangeLimitException) {
            $this->assertTrue(true);
        }
        try {
            $this->transport(['rpc_url' => 'https://rpc.audit.test/deep'])->rpc('eth_chainId', []);
            $this->fail('Excessive JSON depth must fail before envelope processing.');
        } catch (RpcException $exception) {
            $this->assertNotInstanceOf(RangeLimitException::class, $exception);
            $this->assertNull($exception->getPrevious());
        }
    }

    public function test_oversized_send_response_is_ambiguous_and_does_not_expose_private_text(): void
    {
        config(['telegram.max_response_bytes' => 4096]);
        Http::fake(fn () => Http::response(['ok' => true,
            'result' => ['message_id' => 1], 'padding' => str_repeat('private-response-marker', 500),
        ]));
        try {
            app(TelegramClient::class)->sendMessage('1001', 'Private notification');
            $this->fail('Oversized success response cannot prove delivery.');
        } catch (TelegramException $exception) {
            $this->assertTrue($exception->ambiguous);
            $this->assertSame(0, $exception->status);
            $this->assertStringNotContainsString('private-response-marker', (string) $exception);
            $this->assertStringNotContainsString('Private notification', (string) $exception);
        }
    }

    public function test_dense_small_json_is_rejected_before_decoder_allocations(): void
    {
        Http::fake(fn () => Http::response('['.str_repeat('0,', 100000).'0]'));
        $this->expectException(RangeLimitException::class);
        $this->transport()->rpc('eth_chainId', []);
    }

    public function test_json_structure_budget_preserves_log_batches_and_escaped_string_contents(): void
    {
        $quotedValue = str_repeat('[]{}:,\\"', 20000);
        Http::fake(function (Request $request) use ($quotedValue) {
            $result = $request->url() === 'https://rpc.audit.test/logs'
                ? array_fill(0, 1000, $this->log()) : $quotedValue;

            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
        });
        $this->assertSame($quotedValue, $this->transport()->rpc('audit_fixture', []));
        $this->assertCount(1000, $this->transport(['rpc_url' => 'https://rpc.audit.test/logs'])->rpc('eth_getLogs', []));
    }

    public function test_valid_small_responses_still_work_and_non_success_stream_is_closed(): void
    {
        $closed = false;
        $base = Utils::streamFor('unneeded private upstream error body');
        $stream = FnStream::decorate($base, ['close' => static function () use (&$closed, $base): void {
            $closed = true;
            $base->close();
        }]);
        Http::fake([
            'https://rpc.audit.test/' => fn () => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x1']),
            'https://api.telegram.org/*' => fn () => Http::response(['ok' => true, 'result' => [['update_id' => 1]]]),
            'https://rpc.audit.test/denied' => fn () => Http::response($stream, 403),
        ]);
        $this->assertSame('0x1', $this->transport(['max_response_bytes' => 4096])->rpc('eth_chainId', []));
        config(['telegram.max_response_bytes' => 4096]);
        $this->assertSame([['update_id' => 1]], app(TelegramClient::class)->getUpdates(0));
        try {
            $this->transport(['rpc_url' => 'https://rpc.audit.test/denied'])->rpc('eth_chainId', []);
            $this->fail('HTTP error must be rejected.');
        } catch (RpcException $exception) {
            $this->assertSame(403, $exception->httpStatus);
            $this->assertTrue($closed);
        }
    }

    private function transport(array $settings = []): RpcTransport
    {
        return new RpcTransport($settings + ['rpc_url' => 'https://rpc.audit.test/', 'attempts' => 1]);
    }

    private function log(): array
    {
        return [
            'address' => EthereumRpcClient::USDT_CONTRACT,
            'topics' => [EthereumRpcClient::TRANSFER_TOPIC, '0x'.str_repeat('0', 24).str_repeat('2', 40),
                '0x'.str_repeat('0', 24).str_repeat('1', 40)],
            'data' => '0x'.str_repeat('0', 63).'1', 'transactionHash' => '0x'.str_repeat('a', 64),
            'blockHash' => '0x'.str_repeat('b', 64), 'blockNumber' => '0x64', 'logIndex' => '0x0',
        ];
    }
}
