<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Blockchain\AddressCodec;
use App\Blockchain\EthereumRpcClient;
use App\Blockchain\Exceptions\RangeLimitException;
use App\Blockchain\Exceptions\RpcException;
use App\Blockchain\Hex;
use App\Blockchain\RpcTransport;
use App\Blockchain\TronRpcClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BlockchainClientTest extends TestCase
{
    private const HASH = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const BLOCK = '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const SENDER = '0x0000000000000000000000000000000000000001';

    private const RECIPIENT = '0x0000000000000000000000000000000000000002';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_tron_base58check_round_trip_and_network_prefix_are_exact(): void
    {
        $address = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';
        $hex = '0xa614f803b6fd780986a42c78ec9c7f77e6ded13c';
        $this->assertSame($hex, AddressCodec::toEvm('tron', $address));
        $this->assertSame($address, AddressCodec::normalize('tron', $hex));
        $this->assertSame($address, AddressCodec::normalize('tron', '41'.substr($hex, 2)));
        $this->assertSame('0x'.str_pad(substr($hex, 2), 64, '0', STR_PAD_LEFT), AddressCodec::topic('tron', $address));

        $this->expectException(RpcException::class);
        AddressCodec::normalize('tron', 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6u');
    }

    public function test_incoming_filter_uses_recipient_or_list_for_100_addresses(): void
    {
        $addresses = array_map(static fn (int $number): string => '0x'.str_pad(dechex($number), 40, '0', STR_PAD_LEFT), range(1, 100));
        Http::fake(fn (Request $request) => Http::response($this->rpcResponse($request, [$this->log()])));

        $logs = $this->ethereum()->logs(100, 102, $addresses, 'incoming');
        $this->assertCount(1, $logs);
        Http::assertSent(function (Request $request): bool {
            $filter = $request->data()['params'][0];

            return $request->data()['method'] === 'eth_getLogs'
                && $filter['fromBlock'] === '0x64' && $filter['toBlock'] === '0x66'
                && $filter['address'] === EthereumRpcClient::USDT_CONTRACT
                && $filter['topics'][0] === EthereumRpcClient::TRANSFER_TOPIC
                && $filter['topics'][1] === null && count($filter['topics'][2]) === 100;
        });
    }

    public function test_outgoing_filter_does_not_require_a_watched_recipient(): void
    {
        Http::fake(fn (Request $request) => Http::response($this->rpcResponse($request, [$this->log()])));
        $this->assertCount(1, $this->ethereum()->logs(100, 100, [self::SENDER], 'outgoing'));
        Http::assertSent(fn (Request $request): bool => $request->data()['params'][0]['topics'] === [
            EthereumRpcClient::TRANSFER_TOPIC, [AddressCodec::topic('ethereum', self::SENDER)],
        ]);
    }

    public function test_a_provider_cannot_return_an_unwatched_log_as_matching(): void
    {
        Http::fake(fn (Request $request) => Http::response($this->rpcResponse($request, [$this->log()])));
        $this->expectException(RpcException::class);
        $this->ethereum()->logs(100, 100, ['0x0000000000000000000000000000000000000099'], 'incoming');
    }

    public function test_full_receipt_log_order_preserves_unrelated_and_identical_events(): void
    {
        $unrelated = $this->log();
        $unrelated['address'] = self::SENDER;
        $unrelated['topics'] = ['0x'.str_repeat('c', 64)];
        $unrelated['logIndex'] = '0x5';
        $first = $this->log();
        $first['logIndex'] = '0x6';
        $second = $first;
        $second['logIndex'] = '0x7';
        Http::fake(fn (Request $request) => Http::response($this->rpcResponse($request, [
            'transactionHash' => self::HASH, 'blockNumber' => '0x64', 'blockHash' => self::BLOCK,
            'status' => '0x1', 'logs' => [$unrelated, $first, $second],
        ])));

        $receipt = $this->ethereum()->receipt(self::HASH);
        $this->assertCount(3, $receipt['logs']);
        $this->assertSame(self::SENDER, $receipt['logs'][0]['address']);
        $this->assertSame('0x6', $receipt['logs'][1]['logIndex']);
        $this->assertSame('0x7', $receipt['logs'][2]['logIndex']);
    }

    public function test_rate_limit_retries_without_leaking_upstream_details(): void
    {
        Http::fakeSequence()->push(['error' => 'secret-value'], 429, ['Retry-After' => '0'])
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x1']);
        $this->ethereum()->verifyNetwork();
        Http::assertSentCount(2);
    }

    public function test_long_retry_after_returns_control_without_retrying_immediately(): void
    {
        Http::fakeSequence()->push([], 429, ['Retry-After' => '60']);
        try {
            $this->ethereum()->verifyNetwork();
            $this->fail('Expected a retryable rate-limit error.');
        } catch (RpcException $error) {
            $this->assertTrue($error->retryable);
            $this->assertSame(429, $error->httpStatus);
            $this->assertSame(60, $error->retryAfterSeconds);
        }
        Http::assertSentCount(1);
    }

    public function test_authentication_errors_are_not_retried_and_do_not_expose_secrets(): void
    {
        Http::fakeSequence()->push(['error' => 'https://rpc.example.test/secret-key'], 403);
        try {
            $this->ethereum()->verifyNetwork();
            $this->fail('Expected an authentication error.');
        } catch (RpcException $error) {
            $this->assertFalse($error->retryable);
            $this->assertSame(403, $error->httpStatus);
            $this->assertStringNotContainsString('secret-key', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
        Http::assertSentCount(1);
    }

    public function test_redirect_is_not_followed_or_accepted_as_a_chain_response(): void
    {
        Http::fakeSequence()->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x1'], 302, ['Location' => 'https://untrusted.example.test']);
        try {
            $this->ethereum()->verifyNetwork();
            $this->fail('Expected a redirect rejection.');
        } catch (RpcException $error) {
            $this->assertSame(302, $error->httpStatus);
            $this->assertFalse($error->retryable);
        }
        Http::assertSentCount(1);
    }

    public function test_http_200_json_rpc_range_error_requests_query_splitting(): void
    {
        Http::fake(fn (Request $request) => Http::response([
            'jsonrpc' => '2.0', 'id' => $request->data()['id'], 'error' => ['code' => -32005, 'message' => 'too many results'],
        ]));
        $this->expectException(RangeLimitException::class);
        $this->ethereum()->logs(100, 100, [self::RECIPIENT], 'incoming');
    }

    public function test_connection_failures_are_bounded_and_sanitized(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('https://rpc.example.test/secret-key failed');
        });
        try {
            $this->ethereum()->verifyNetwork();
            $this->fail('Expected a connection failure.');
        } catch (RpcException $error) {
            $this->assertTrue($error->retryable);
            $this->assertSame(3, $calls);
            $this->assertStringNotContainsString('secret-key', $error->getMessage());
        }
    }

    public function test_malformed_json_is_not_accepted_as_an_empty_result(): void
    {
        Http::fake(['*' => Http::response('not JSON', 200, ['Content-Type' => 'application/json'])]);
        $this->expectException(RpcException::class);
        $this->ethereum()->logs(100, 100, [self::RECIPIENT], 'incoming');
    }

    public function test_exact_result_ceiling_is_not_assumed_complete(): void
    {
        Http::fake(fn (Request $request) => Http::response($this->rpcResponse($request, [$this->log()])));
        $this->expectException(RangeLimitException::class);
        $this->ethereum(['max_results' => 1])->logs(100, 100, [self::RECIPIENT], 'incoming');
    }

    public function test_http_200_rate_limit_body_is_retried(): void
    {
        Http::fakeSequence()->push(['success' => false, 'error' => 'The key exceeds the frequency limit', 'statusCode' => 429])
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x1']);
        $this->ethereum()->verifyNetwork();
        Http::assertSentCount(2);
    }

    public function test_wrong_network_is_rejected_before_scanning(): void
    {
        Http::fake(fn (Request $request) => Http::response($this->rpcResponse($request, '0x89')));
        $this->expectException(RpcException::class);
        $this->ethereum()->verifyNetwork();
    }

    public function test_malformed_response_and_mismatched_request_id_are_rejected(): void
    {
        Http::fake(['*' => Http::response(['jsonrpc' => '2.0', 'id' => 999, 'result' => '0x1'])]);
        $this->expectException(RpcException::class);
        $this->ethereum()->verifyNetwork();
    }

    public function test_http_200_native_error_is_not_a_valid_solidified_head(): void
    {
        Http::fake(['*' => Http::response(['Error' => 'upstream unavailable secret-key'])]);
        try {
            $this->tron()->finalizedBlock();
            $this->fail('Expected a native blockchain error.');
        } catch (RpcException $error) {
            $this->assertStringNotContainsString('secret-key', $error->getMessage());
        }
    }

    public function test_tron_finality_uses_native_solidity_not_gateway_finality_tags(): void
    {
        Http::fake(['https://tron.example.test/*' => Http::response($this->nativeBlock())]);
        $this->assertSame(['number' => 100, 'hash' => self::BLOCK, 'timestamp' => 1700000000], $this->tron()->finalizedBlock());
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://tron.example.test/walletsolidity/getnowblock' && $request->body() === '{}');
    }

    public function test_tron_native_receipt_normalization_keeps_full_log_positions(): void
    {
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/getblockbynum')) {
                return Http::response($this->nativeBlock());
            }

            return Http::response([
                'id' => substr(self::HASH, 2), 'blockNumber' => 100, 'receipt' => ['result' => 'SUCCESS'],
                'log' => [
                    ['address' => substr(self::SENDER, 2), 'topics' => [], 'data' => ''],
                    ['address' => substr(TronRpcClient::USDT_CONTRACT, 2), 'topics' => [substr(EthereumRpcClient::TRANSFER_TOPIC, 2), str_pad('1', 64, '0', STR_PAD_LEFT), str_pad('2', 64, '0', STR_PAD_LEFT)], 'data' => str_pad('f4240', 64, '0', STR_PAD_LEFT)],
                ],
            ]);
        });

        $receipt = $this->tron()->receipt(self::HASH);
        $this->assertSame('0x1', $receipt['status']);
        $this->assertSame(self::BLOCK, $receipt['blockHash']);
        $this->assertCount(2, $receipt['logs']);
        $this->assertSame(1, $receipt['logs'][1]['receiptLogPosition']);
        $this->assertSame(TronRpcClient::USDT_CONTRACT, $receipt['logs'][1]['address']);
    }

    public function test_invalid_hex_quantities_do_not_overflow_into_floats(): void
    {
        $this->expectException(RpcException::class);
        Hex::integer('0xffffffffffffffffffffffffffffffff');
    }

    public function test_native_block_fallback_does_not_accept_missing_receipts(): void
    {
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/getblockbynum')) {
                return Http::response($this->nativeBlock() + ['transactions' => [['txID' => substr(self::HASH, 2)]]]);
            }

            return Http::response([]);
        });
        try {
            $this->tron()->solidifiedBlockReceipts(100);
            $this->fail('Expected incomplete receipts to block fallback coverage.');
        } catch (RpcException $error) {
            $this->assertTrue($error->retryable);
            $this->assertSame('Solidified block receipts are incomplete.', $error->getMessage());
        }
    }

    private function ethereum(array $limits = []): EthereumRpcClient
    {
        return new EthereumRpcClient(new RpcTransport($this->settings()), 'primary', $limits);
    }

    private function tron(): TronRpcClient
    {
        return new TronRpcClient(new RpcTransport($this->settings()), 'primary');
    }

    private function settings(): array
    {
        return ['rpc_url' => 'https://rpc.example.test/secret-key', 'native_url' => 'https://tron.example.test', 'attempts' => 3, 'backoff_ms' => 0, 'max_backoff_ms' => 0];
    }

    private function rpcResponse(Request $request, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $request->data()['id'], 'result' => $result];
    }

    private function nativeBlock(): array
    {
        return ['blockID' => substr(self::BLOCK, 2), 'block_header' => ['raw_data' => ['number' => 100, 'timestamp' => 1700000000000]]];
    }

    private function log(): array
    {
        return [
            'address' => EthereumRpcClient::USDT_CONTRACT, 'blockNumber' => '0x64', 'blockHash' => self::BLOCK,
            'transactionHash' => self::HASH, 'logIndex' => '0x0', 'removed' => false,
            'topics' => [EthereumRpcClient::TRANSFER_TOPIC, AddressCodec::topic('ethereum', self::SENDER), AddressCodec::topic('ethereum', self::RECIPIENT)],
            'data' => '0x'.str_pad('f4240', 64, '0', STR_PAD_LEFT),
        ];
    }
}
