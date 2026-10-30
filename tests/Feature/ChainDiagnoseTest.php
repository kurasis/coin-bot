<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChainDiagnoseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['ethereum', 'tron'] as $chain) {
            foreach (['primary', 'secondary'] as $role) {
                config(['blockchain.chains.'.$chain.'.providers.'.$role => [
                    'rpc_url' => 'https://provider.test/'.$chain.'/'.$role.'/PRIVATE-KEY',
                    'native_url' => 'https://provider.test/'.$chain.'/'.$role.'/PRIVATE-NATIVE-KEY',
                    'history_start_block' => 0,
                    'attempts' => 1,
                    'backoff_ms' => 0,
                ]]);
            }
        }
    }

    public function test_success_checks_both_ethereum_heads_without_any_database_changes(): void
    {
        $this->pending('ethereum', 990, 1000);
        DB::table('users')->insert(['telegram_user_id' => '987654321']);
        DB::table('provider_health')->insert(['chain' => 'ethereum', 'provider' => 'primary', 'failure_count' => 2, 'last_http_status' => 503, 'updated_at' => now()]);
        $before = $this->snapshot();
        $this->healthy();

        [$status, $report, $output] = $this->diagnose('ethereum');

        $this->assertSame(0, $status);
        $this->assertTrue($report['read_only']);
        $this->assertSame(['count' => 1, 'oldest_from_block' => 990, 'oldest_to_block' => 1000], $report['pending_reconciliation']);
        $this->assertSame('prerequisites_ready_not_verified', $report['reconciliation']);
        foreach (['primary', 'secondary'] as $role) {
            $this->assertSame('verified', $report['providers'][$role]['network']);
            $this->assertSame(1, $report['providers'][$role]['chain_id']);
            $this->assertSame(1000, $report['providers'][$role]['finalized_block']);
            $this->assertSame(1010, $report['providers'][$role]['latest_block']);
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertStringNotContainsString('PRIVATE', $output);
        $this->assertStringNotContainsString('987654321', $output);
        Http::assertSentCount(6);
    }

    public function test_offline_report_exposes_effective_query_limits_without_private_configuration(): void
    {
        config(['blockchain.chains.ethereum.providers.secondary.max_block_range' => 5,
            'blockchain.chains.ethereum.providers.secondary.max_topic_addresses' => 50,
            'reconciliation.recheck_after_seconds' => 900]);
        $before = $this->snapshot();

        [$status, $report, $output] = $this->diagnose('ethereum', ['--offline' => true]);

        $this->assertSame(0, $status);
        $this->assertSame(['max_block_range' => 5, 'max_topic_addresses' => 50], $report['providers']['secondary']['query_limits']);
        $this->assertSame(900, $report['resource_controls']['reconciliation_recheck_seconds']);
        $this->assertSame($before, $this->snapshot());
        $this->assertStringNotContainsString('PRIVATE', $output);
        $this->assertStringNotContainsString('provider.test', $output);
        Http::assertNothingSent();
    }

    #[DataProvider('httpFailures')]
    public function test_http_failures_are_precise_and_sanitized_without_opening_a_circuit(int $httpStatus, string $reason): void
    {
        $before = $this->snapshot();
        Http::fake(['*' => Http::response('SECRET upstream error https://private.test/KEY', $httpStatus, ['Retry-After' => '3600'])]);

        [$status, $report, $output] = $this->diagnose('ethereum', ['--provider' => 'primary']);

        $this->assertSame(1, $status);
        $error = $report['providers']['primary']['error'];
        $this->assertSame('network', $error['phase']);
        $this->assertSame($reason, $error['reason']);
        $this->assertSame($httpStatus, $error['http_status']);
        $this->assertSame(3600, $error['retry_after_seconds']);
        $this->assertSame($before, $this->snapshot());
        $this->assertStringNotContainsString('SECRET', $output);
        $this->assertStringNotContainsString('private.test', $output);
        $this->assertStringNotContainsString('PRIVATE-KEY', $output);
        Http::assertSentCount(1);
    }

    public static function httpFailures(): array
    {
        return [
            'unauthorized' => [401, 'authentication_or_permission'],
            'forbidden' => [403, 'authentication_or_permission'],
            'quota' => [429, 'rate_limited'],
            'unavailable' => [503, 'upstream_unavailable'],
            'rejected' => [400, 'request_rejected'],
            'too-large' => [413, 'request_too_large'],
        ];
    }

    #[DataProvider('chains')]
    public function test_wrong_chain_id_stops_before_heads_and_exposes_no_endpoint(string $chain): void
    {
        Http::fake(fn (Request $request) => Http::response(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => '0x2']));

        [$status, $report, $output] = $this->diagnose($chain, ['--provider' => 'primary']);

        $this->assertSame(1, $status);
        $this->assertSame('wrong_network', $report['providers']['primary']['error']['reason']);
        $this->assertSame('not_checked', $report['providers']['primary']['network']);
        $this->assertNull($report['providers']['primary']['finalized_block']);
        $this->assertStringNotContainsString('PRIVATE', $output);
        Http::assertSentCount(1);
    }

    public static function chains(): array
    {
        return [['ethereum'], ['tron']];
    }

    public function test_malformed_json_is_static_and_does_not_modify_operational_state(): void
    {
        $before = $this->snapshot();
        Http::fake(['*' => Http::response('{SECRET invalid JSON')]);

        [$status, $report, $output] = $this->diagnose('ethereum', ['--provider' => 'primary']);

        $this->assertSame(1, $status);
        $this->assertSame('invalid_response', $report['providers']['primary']['error']['reason']);
        $this->assertStringNotContainsString('SECRET', $output);
        $this->assertSame($before, $this->snapshot());
        Http::assertSentCount(1);
    }

    public function test_an_unsupported_finalized_query_identifies_the_failing_phase_without_upstream_text(): void
    {
        Http::fake(fn (Request $request) => Http::response($request['method'] === 'eth_chainId'
            ? ['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => '0x1']
            : ['jsonrpc' => '2.0', 'id' => $request['id'], 'error' => ['code' => -32601, 'message' => 'SECRET unsupported finalized tag']]));

        [$status, $report, $output] = $this->diagnose('ethereum', ['--provider' => 'primary']);

        $this->assertSame(1, $status);
        $this->assertSame('verified', $report['providers']['primary']['network']);
        $this->assertSame('finalized', $report['providers']['primary']['error']['phase']);
        $this->assertSame('rpc_error', $report['providers']['primary']['error']['reason']);
        $this->assertStringNotContainsString('SECRET', $output);
        Http::assertSentCount(2);
    }

    public function test_existing_mandatory_cooldown_is_respected_without_requests_or_state_changes(): void
    {
        $this->freezeTime();
        DB::table('provider_health')->insert(['chain' => 'ethereum', 'provider' => 'primary', 'failure_count' => 3, 'opens_until' => now()->addHour(), 'last_http_status' => 429]);
        $before = $this->snapshot();

        [$status, $report] = $this->diagnose('ethereum', ['--provider' => 'primary']);

        $this->assertSame(1, $status);
        $this->assertSame('cooldown', $report['providers']['primary']['status']);
        $this->assertSame(3600, $report['providers']['primary']['retry_after_seconds']);
        $this->assertSame(['http_status' => 429, 'consecutive_failures' => 3], $report['providers']['primary']['last_failure']);
        $this->assertSame($before, $this->snapshot());
        Http::assertNothingSent();
    }

    public function test_offline_report_identifies_unknown_history_without_certifying_it(): void
    {
        $this->pending('tron', 990, 1000);
        config(['blockchain.chains.tron.providers.secondary.history_start_block' => null]);
        $before = $this->snapshot();

        [$status, $report] = $this->diagnose('tron', ['--offline' => true]);

        $this->assertSame(0, $status);
        $this->assertTrue($report['offline']);
        $this->assertSame('offline', $report['providers']['primary']['status']);
        $this->assertSame('unknown', $report['providers']['secondary']['history_floor']);
        $this->assertFalse($report['providers']['secondary']['history_covers_oldest_pending']);
        $this->assertSame('waiting_for_history_configuration', $report['reconciliation']);
        $this->assertSame($before, $this->snapshot());
        Http::assertNothingSent();
    }

    public function test_declared_history_floor_above_oldest_pending_is_distinguished_from_finality(): void
    {
        $this->pending('ethereum', 990, 1000);
        config(['blockchain.chains.ethereum.providers.secondary.history_start_block' => 995]);
        $this->healthy(999);

        [$status, $report] = $this->diagnose('ethereum');

        $this->assertSame(0, $status);
        $this->assertSame('declared', $report['providers']['secondary']['history_floor']);
        $this->assertFalse($report['providers']['secondary']['history_covers_oldest_pending']);
        $this->assertSame('waiting_for_history_configuration', $report['reconciliation']);
    }

    public function test_secondary_finality_below_oldest_pending_is_reported_separately(): void
    {
        $this->pending('ethereum', 990, 1000);
        $this->healthy(999);

        [$status, $report] = $this->diagnose('ethereum');

        $this->assertSame(0, $status);
        $this->assertSame('waiting_for_finality', $report['reconciliation']);
        $this->assertSame(999, $report['providers']['secondary']['finalized_block']);
    }

    public function test_tron_checks_json_rpc_network_and_native_solidified_head_only(): void
    {
        $this->pending('tron', 990, 1000);
        $this->healthy();
        $before = $this->snapshot();

        [$status, $report] = $this->diagnose('tron');

        $this->assertSame(0, $status);
        $this->assertSame(1000, $report['providers']['primary']['finalized_block']);
        $this->assertSame(728126428, $report['providers']['primary']['chain_id']);
        $this->assertNull($report['providers']['primary']['latest_block']);
        $this->assertSame('prerequisites_ready_not_verified', $report['reconciliation']);
        $this->assertSame($before, $this->snapshot());
        Http::assertSentCount(4);
        Http::assertNotSent(fn (Request $request): bool => ($request->data()['method'] ?? null) === 'eth_getBlockByNumber');
    }

    public function test_selected_provider_never_probes_the_other_role_or_claims_reconciliation_verified(): void
    {
        $this->pending('ethereum', 990, 1000);
        $this->healthy();

        [$status, $report] = $this->diagnose('ethereum', ['--provider' => 'primary']);

        $this->assertSame(0, $status);
        $this->assertSame('not_selected', $report['providers']['secondary']['status']);
        $this->assertSame('heads_not_checked', $report['reconciliation']);
        Http::assertSentCount(3);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/secondary/'));
    }

    public function test_missing_native_endpoint_is_not_probed_or_silently_treated_as_tron_finality(): void
    {
        config(['blockchain.chains.tron.providers.primary.native_url' => null]);

        [$status, $report] = $this->diagnose('tron', ['--provider' => 'primary']);

        $this->assertSame(1, $status);
        $this->assertSame('not_configured', $report['providers']['primary']['status']);
        $this->assertFalse($report['providers']['primary']['native_configured']);
        Http::assertNothingSent();
    }

    public function test_invalid_chain_and_provider_are_rejected_without_requests(): void
    {
        $this->assertSame(2, Artisan::call('chain:diagnose', ['chain' => 'bitcoin']));
        $this->assertSame(['error' => 'invalid_chain_or_provider'], json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame(2, Artisan::call('chain:diagnose', ['chain' => 'tron', '--provider' => 'SECRET']));
        $this->assertStringNotContainsString('SECRET', Artisan::output());
        Http::assertNothingSent();
    }

    #[DataProvider('reconciliationWaits')]
    public function test_reconciliation_command_names_its_actual_waiting_reason(string $reason, string $message): void
    {
        if ($reason !== 'idle') {
            $this->pending('ethereum', 990, 1000);
        }
        if ($reason === 'history') {
            config(['blockchain.chains.ethereum.providers.secondary.history_start_block' => null]);
        }
        if ($reason === 'finality') {
            $this->healthy(999);
        }

        $this->assertSame(0, Artisan::call('chain:reconcile', ['chain' => 'ethereum', '--once' => true]));
        $this->assertStringContainsString($message, Artisan::output());
        if ($reason !== 'finality') {
            Http::assertNothingSent();
        }
    }

    public static function reconciliationWaits(): array
    {
        return [
            'history' => ['history', 'Reconciliation is waiting for verified provider history settings.'],
            'finality' => ['finality', 'Reconciliation is waiting for both providers to finalize the pending range.'],
            'idle' => ['idle', 'No completed finalized range is awaiting reconciliation.'],
        ];
    }

    private function diagnose(string $chain, array $options = []): array
    {
        $status = Artisan::call('chain:diagnose', ['chain' => $chain] + $options);
        $output = Artisan::output();

        return [$status, json_decode($output, true, flags: JSON_THROW_ON_ERROR), $output];
    }

    private function healthy(int $secondaryHead = 1000): void
    {
        Http::fake(function (Request $request) use ($secondaryHead) {
            if (str_ends_with($request->url(), '/walletsolidity/getnowblock')) {
                return Http::response(['blockID' => str_repeat('a', 64), 'block_header' => ['raw_data' => ['number' => 1000, 'timestamp' => 1793030400000]]]);
            }
            $result = match ($request['method']) {
                'eth_chainId' => str_contains($request->url(), '/tron/') ? '0x2b6653dc' : '0x1',
                'eth_getBlockByNumber' => [
                    'number' => '0x'.dechex($request['params'][0] === 'latest' ? 1010 : (str_contains($request->url(), '/secondary/') ? $secondaryHead : 1000)),
                    'hash' => '0x'.str_repeat('a', 64), 'timestamp' => '0x6adfe400',
                ],
                default => null,
            };

            return Http::response(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => $result]);
        });
    }

    private function pending(string $chain, int $from, int $to): void
    {
        $set = DB::table('watch_sets')->insertGetId(['chain' => $chain, 'version' => 1, 'cutover_block' => $from - 1, 'activated_at' => now()]);
        $cursor = DB::table('scan_cursors')->insertGetId(['chain' => $chain, 'role' => 'primary', 'provider' => 'primary', 'watch_set_id' => $set, 'last_block' => $to]);
        DB::table('scan_ranges')->insert(['cursor_id' => $cursor, 'watch_set_id' => $set, 'from_block' => $from, 'to_block' => $to, 'status' => 'completed', 'completed_at' => now()]);
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table) {
            $rows = DB::table($table->name)->get()->map(fn (object $row): string => json_encode($row, JSON_THROW_ON_ERROR))->all();
            sort($rows);
            $snapshot[$table->name] = $rows;
        }

        return $snapshot;
    }
}
