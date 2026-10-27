<?php

namespace App\Console\Commands;

use App\Blockchain\ChainClientFactory;
use App\Monitoring\CheckAttempt;
use App\Monitoring\CheckedChainClient;
use App\Monitoring\MonitoringCycle;
use App\Monitoring\MonitoringReadiness;
use App\Monitoring\ScanException;
use App\Operations\Heartbeat;
use App\Operations\MaintenanceGuard;
use App\Operations\MonitoringIncidentService;
use App\Operations\SanitizedFailure;
use App\Reconciliation\ResilientEarlyScanner;
use Illuminate\Console\Command;
use Throwable;

final class EthereumEarlyScan extends Command
{
    protected $signature = 'ethereum:early-scan {--once} {--range=100} {--interval=15}';

    protected $description = 'Observe preliminary Ethereum USDT after confirmations without advancing finalized coverage';

    public function handle(ChainClientFactory $factory, ResilientEarlyScanner $scanner, MonitoringCycle $cycles, MonitoringReadiness $readiness, MonitoringIncidentService $incidents, Heartbeat $heartbeat): int
    {
        $range = filter_var($this->option('range'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        $interval = filter_var($this->option('interval'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3600]]);
        if ($range === false || $interval === false) {
            return self::INVALID;
        }
        do {
            $attempt = null;
            $idle = true;
            $catchingUp = false;
            try {
                if (! config('ethereum.early_enabled', false)) {
                    $heartbeat->record('scan-ethereum-early');
                    $this->line('Early Ethereum notifications are disabled; finalized monitoring is unchanged.');
                } else {
                    $state = $readiness->state('ethereum', true);
                    if ($state !== 'ready') {
                        if ($state === 'safety_paused') {
                            $incidents->failed('ethereum', 'scanner_failed');
                        }
                        $this->line('Early monitoring is waiting for setup, resume, or a safety review.');
                        if ($this->option('once')) {
                            return self::FAILURE;
                        }
                    } else {
                        $attempt = new CheckAttempt;
                        $primary = new CheckedChainClient($factory->make('ethereum'), $attempt);
                        $secondary = config('blockchain.chains.ethereum.providers.secondary.rpc_url')
                            ? new CheckedChainClient($factory->make('ethereum', 'secondary'), $attempt) : null;
                        $result = $cycles->run('ethereum', fn () => $scanner->scan($primary, $secondary, $range), $attempt);
                        $idle = $result->idle;
                        $catchingUp = ! $idle && $result->toBlock - $result->fromBlock + 1 >= $range;
                        $heartbeat->record('scan-ethereum-early');
                        $this->line($idle ? 'No new confirmation-qualified Ethereum blocks.' : "Observed preliminary blocks {$result->fromBlock}-{$result->toBlock}: {$result->events} events.");
                    }
                }
            } catch (Throwable $error) {
                $catchingUp = false;
                if ($error instanceof ScanException && ! app()->isDownForMaintenance() && ! app(MaintenanceGuard::class)->isPaused()) {
                    try {
                        $incidents->failed('ethereum', 'scanner_failed');
                    } catch (Throwable) {
                        // No credential-bearing exception text is logged.
                    }
                }
                $this->error('Early Ethereum check did not complete; finalized coverage and durable observations are preserved.');
                $this->line(json_encode(['failure' => SanitizedFailure::details($error),
                    'providers' => $attempt?->failures ?? []], JSON_THROW_ON_ERROR));
                if ($this->option('once')) {
                    return self::FAILURE;
                }
            }
            if (! $this->option('once')) {
                // Avoid an extra head/RPC burst after every ordinary new block.
                sleep($catchingUp ? 1 : $interval);
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }
}
