<?php

namespace Tests\Feature;

use App\Blockchain\ChainClientFactory;
use App\Blockchain\Hex;
use App\Monitoring\BlockScanner;
use App\Monitoring\EthereumEarlyScanner;
use App\Operations\ApiPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApiBudgetSimulationTest extends TestCase
{
    use RefreshDatabase;

    /** Fixed one-hour schedule; excludes RPC latency, reconciliation, receipts and retries. */
    #[DataProvider('pollIntervals')]
    public function test_sparse_ethereum_monitoring_keeps_coverage_with_bounded_polling_cost(int $earlyInterval, int $maximumCu): void
    {
        config(['ethereum.early_enabled' => true,
            'blockchain.chains.ethereum.providers.primary' => [
                'rpc_url' => 'https://eth-mainnet.g.alchemy.com/v2/test',
                'max_block_range' => 10, 'max_topic_addresses' => 100,
            ]]);
        $wallet = DB::table('wallets')->insertGetId(['chain' => 'ethereum', 'address' => '0x'.str_repeat('1', 40)]);
        $set = DB::table('watch_sets')->insertGetId(['chain' => 'ethereum', 'version' => 1, 'cutover_block' => 936, 'activated_at' => now()]);
        DB::table('watch_set_wallets')->insert(['watch_set_id' => $set, 'wallet_id' => $wallet]);
        $header = static fn (int $height): array => ['number' => Hex::quantity($height),
            'hash' => '0x'.hash('sha256', 'budget:'.$height), 'timestamp' => Hex::quantity(1700000000 + $height * 12)];
        $seconds = 0;
        $units = 0;
        $policy = new ApiPolicy('alchemy', 'alchemy', 0, 800000);
        Http::fake(function (Request $request) use (&$seconds, &$units, $policy, $header) {
            $units += $policy->billedUnits($request['method']);
            $result = match ($request['method']) {
                'eth_chainId' => '0x1',
                'eth_getLogs' => [],
                'eth_getBlockByNumber' => $header(match ($request['params'][0]) {
                    'latest' => 1006 + intdiv($seconds, 12),
                    'finalized' => 936 + intdiv($seconds, 384) * 32,
                    default => Hex::integer($request['params'][0]),
                }),
            };

            return Http::response(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => $result]);
        });
        $client = (new ChainClientFactory)->make('ethereum');
        $scanner = new BlockScanner;
        $cursor = $scanner->initialize('ethereum', 937, $set);
        DB::table('scan_cursors')->where('id', $cursor)->update(['last_block_hash' => $header(936)['hash']]);
        $early = new EthereumEarlyScanner;
        $early->scan($client);
        $units = 0;

        for ($seconds = 15; $seconds <= 3600; $seconds += 15) {
            $scanner->scan($client);
            if ($seconds % $earlyInterval === 0) {
                $early->scan($client);
            }
        }

        $this->assertSame(1224, (int) DB::table('scan_cursors')->find($cursor)->last_block);
        $this->assertSame(1300, (int) DB::table('ethereum_early_state')->sole()->last_block);
        $this->assertDatabaseCount('transfers', 0);
        $this->assertDatabaseCount('notification_outbox', 0);
        $this->assertLessThanOrEqual($maximumCu, $units);
        if (getenv('COIN_BOT_BUDGET_BENCHMARK')) {
            fwrite(STDERR, "\nEarly interval {$earlyInterval}s: {$units} Alchemy CU per model hour.\n");
        }
    }

    public static function pollIntervals(): array
    {
        return ['15 seconds' => [15, 65000], '30 seconds' => [30, 38000]];
    }
}
