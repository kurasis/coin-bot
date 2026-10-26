<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Blockchain\AddressCodec;
use App\Blockchain\EthereumRpcClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BlockchainProbeCommandTest extends TestCase
{
    private const ADDRESS = '0x0000000000000000000000000000000000000002';

    private const BLOCK = '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const HASH = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['blockchain.chains.ethereum.providers.primary' => ['rpc_url' => 'https://rpc.example.test/private-provider-key', 'attempts' => 1]]);
    }

    public function test_missing_addresses_cannot_trigger_a_busy_global_scan(): void
    {
        $this->artisan('chain:probe ethereum')->expectsOutputToContain('Provide at least one --address')->assertExitCode(2);
        Http::assertNothingSent();
    }

    public function test_missing_provider_configuration_fails_without_printing_secrets(): void
    {
        config(['blockchain.chains.ethereum.providers.primary.rpc_url' => null]);
        $this->artisan('chain:probe', ['chain' => 'ethereum', '--address' => [self::ADDRESS]])
            ->expectsOutputToContain('A valid HTTPS blockchain endpoint is required.')->assertExitCode(1);
        Http::assertNothingSent();
    }

    public function test_network_errors_have_sanitized_command_output(): void
    {
        Http::fakeSequence()->push(['error' => 'private-provider-key'], 403);
        $this->artisan('chain:probe', ['chain' => 'ethereum', '--address' => [self::ADDRESS]])
            ->expectsOutputToContain('Probe failed: Blockchain HTTP request failed.')
            ->doesntExpectOutputToContain('private-provider-key')->assertExitCode(1);
    }

    public function test_probe_verifies_a_matching_full_receipt_without_persisting_or_sending(): void
    {
        $this->fakeProbe();
        $this->artisan('chain:probe', ['chain' => 'ethereum', '--address' => [self::ADDRESS]])
            ->expectsOutputToContain('"receipts_checked":1')
            ->doesntExpectOutputToContain('private-provider-key')->assertExitCode(0);
        Http::assertSentCount(6);
    }

    public function test_probe_rejects_a_discovered_event_absent_from_its_receipt(): void
    {
        $this->fakeProbe(true);
        $this->artisan('chain:probe', ['chain' => 'ethereum', '--address' => [self::ADDRESS]])
            ->expectsOutputToContain('A sampled receipt does not contain a discovered transfer.')->assertExitCode(1);
    }

    private function fakeProbe(bool $emptyReceipt = false): void
    {
        Http::fake(function (Request $request) use ($emptyReceipt) {
            $data = $request->data();
            $log = [
                'address' => EthereumRpcClient::USDT_CONTRACT, 'transactionHash' => self::HASH,
                'blockNumber' => '0x64', 'blockHash' => self::BLOCK, 'logIndex' => '0x0',
                'topics' => [EthereumRpcClient::TRANSFER_TOPIC, AddressCodec::topic('ethereum', '0x0000000000000000000000000000000000000001'), AddressCodec::topic('ethereum', self::ADDRESS)],
                'data' => '0x'.str_pad('f4240', 64, '0', STR_PAD_LEFT),
            ];
            $result = match ($data['method']) {
                'eth_chainId' => '0x1',
                'eth_getBlockByNumber' => ['number' => '0x64', 'hash' => self::BLOCK, 'timestamp' => '0x6553f100'],
                'eth_getLogs' => count($data['params'][0]['topics']) === 3 ? [$log] : [],
                'eth_getTransactionReceipt' => ['transactionHash' => self::HASH, 'blockNumber' => '0x64', 'blockHash' => self::BLOCK, 'status' => '0x1', 'logs' => $emptyReceipt ? [] : [$log]],
            };

            return Http::response(['jsonrpc' => '2.0', 'id' => $data['id'], 'result' => $result]);
        });

    }
}
