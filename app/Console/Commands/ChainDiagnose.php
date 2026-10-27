<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Blockchain\ChainClientFactory;
use App\Blockchain\Exceptions\RpcException;
use App\Operations\SanitizedFailure;
use App\Reconciliation\ProviderCircuitBreaker;
use App\Reconciliation\ProviderCoolingDown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ChainDiagnose extends Command
{
    protected $signature = 'chain:diagnose {chain : ethereum or tron} {--provider=all : all, primary, or secondary} {--offline : Inspect local configuration without network requests}';

    protected $description = 'Read provider availability and reconciliation prerequisites without changing monitoring state';

    public function handle(ChainClientFactory $factory, ProviderCircuitBreaker $circuits): int
    {
        $chain = (string) $this->argument('chain');
        $role = (string) $this->option('provider');
        if (! in_array($chain, ['ethereum', 'tron'], true) || ! in_array($role, ['all', 'primary', 'secondary'], true)) {
            $this->line('{"error":"invalid_chain_or_provider"}');

            return self::INVALID;
        }

        try {
            $cursor = DB::table('scan_cursors')->where('chain', $chain)->where('role', 'primary')->where('provider', 'primary')->first();
            $pending = DB::table('scan_ranges')->where('cursor_id', $cursor?->id ?? -1)->where('status', 'completed')->whereNull('reconciled_at');
            $oldest = (clone $pending)->orderBy('from_block')->first();
            $report = [
                'chain' => $chain,
                'read_only' => true,
                'offline' => (bool) $this->option('offline'),
                'cursor' => ['exists' => $cursor !== null, 'paused' => $cursor?->paused_at !== null, 'last_block' => $cursor === null ? null : (int) $cursor->last_block],
                'pending_reconciliation' => [
                    'count' => $pending->count(),
                    'oldest_from_block' => $oldest === null ? null : (int) $oldest->from_block,
                    'oldest_to_block' => $oldest === null ? null : (int) $oldest->to_block,
                ],
                'providers' => [],
            ];
            foreach (['primary', 'secondary'] as $provider) {
                $report['providers'][$provider] = $this->provider($chain, $provider, $role === 'all' || $role === $provider, $oldest, $factory, $circuits);
            }
            $report['reconciliation'] = $this->readiness($report);
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return collect($report['providers'])->contains(fn (array $provider): bool => in_array($provider['status'], ['error', 'cooldown', 'not_configured'], true))
                ? self::FAILURE : self::SUCCESS;
        } catch (Throwable) {
            $this->line('{"error":"diagnostic_unavailable","read_only":true}');

            return self::FAILURE;
        }
    }

    private function provider(string $chain, string $role, bool $selected, ?object $oldest, ChainClientFactory $factory, ProviderCircuitBreaker $circuits): array
    {
        $settings = config('blockchain.chains.'.$chain.'.providers.'.$role, []);
        $floor = $settings['history_start_block'] ?? null;
        $minimum = $floor === null ? false : filter_var($floor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $report = [
            'rpc_configured' => is_string($settings['rpc_url'] ?? null) && trim($settings['rpc_url']) !== '',
            'native_configured' => $chain === 'tron' ? is_string($settings['native_url'] ?? null) && trim($settings['native_url']) !== '' : null,
            // This describes the operator's declaration; the diagnostic never certifies retention.
            'history_start_block' => $minimum === false ? null : $minimum,
            'history_floor' => $floor === null ? 'unknown' : ($minimum === false ? 'invalid' : 'declared'),
            'history_covers_oldest_pending' => $oldest === null ? null : ($minimum !== false && (int) $oldest->from_block >= $minimum),
            'status' => $selected ? 'not_checked' : 'not_selected',
            'network' => 'not_checked',
            'chain_id' => null,
            'finalized_block' => null,
            'latest_block' => null,
        ];
        if (! $selected) {
            return $report;
        }
        if (! $report['rpc_configured'] || ($chain === 'tron' && ! $report['native_configured'])) {
            $report['status'] = 'not_configured';

            return $report;
        }
        $state = DB::table('provider_health')->where('chain', $chain)->where('provider', $role)->first();
        $report['last_failure'] = $state === null ? null : [
            'http_status' => $state->last_http_status === null ? null : (int) $state->last_http_status,
            'consecutive_failures' => (int) $state->failure_count,
        ];
        $phase = 'configuration';
        try {
            $client = $factory->make($chain, $role);
            // assertAvailable only reads circuit state. Never clear or open a circuit here.
            $circuits->assertAvailable($client);
            if ($this->option('offline')) {
                $report['status'] = 'offline';

                return $report;
            }
            $phase = 'network';
            $client->verifyNetwork();
            $report['network'] = 'verified';
            $report['chain_id'] = $chain === 'ethereum' ? 1 : 728126428;
            $phase = 'finalized';
            $report['finalized_block'] = $client->finalizedBlock()['number'];
            if ($chain === 'ethereum' && is_callable([$client, 'latestBlock'])) {
                $phase = 'latest';
                $report['latest_block'] = $client->latestBlock()['number'];
            }
            $report['status'] = 'ok';
        } catch (ProviderCoolingDown $error) {
            $report['status'] = 'cooldown';
            $report['retry_after_seconds'] = $error->retryAfterSeconds;
        } catch (RpcException $error) {
            $report['status'] = 'error';
            $report['error'] = ['phase' => $phase] + SanitizedFailure::details($error);
        } catch (Throwable) {
            $report['status'] = 'error';
            $report['error'] = ['phase' => $phase, 'reason' => 'diagnostic_failed'];
        }

        return $report;
    }

    private function readiness(array $report): string
    {
        if (! $report['cursor']['exists']) {
            return 'no_live_cursor';
        }
        if ($report['cursor']['paused']) {
            return 'cursor_paused';
        }
        if ($report['pending_reconciliation']['count'] === 0) {
            return 'no_pending_range';
        }
        foreach ($report['providers'] as $provider) {
            if (! $provider['history_covers_oldest_pending']) {
                return 'waiting_for_history_configuration';
            }
        }
        foreach ($report['providers'] as $provider) {
            if (in_array($provider['status'], ['error', 'cooldown', 'not_configured'], true)) {
                return 'provider_unavailable';
            }
        }
        foreach ($report['providers'] as $provider) {
            if ($provider['finalized_block'] === null) {
                return 'heads_not_checked';
            }
        }
        foreach ($report['providers'] as $provider) {
            if ($provider['finalized_block'] < $report['pending_reconciliation']['oldest_to_block']) {
                return 'waiting_for_finality';
            }
        }

        return 'prerequisites_ready_not_verified';
    }
}
