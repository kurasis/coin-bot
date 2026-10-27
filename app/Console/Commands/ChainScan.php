<?php

declare(strict_types=1);

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
use App\Reconciliation\ResilientScanner;
use Illuminate\Console\Command;
use Throwable;

class ChainScan extends Command
{
    protected $signature = 'chain:scan {chain : ethereum or tron} {--once} {--provider=primary} {--range=100} {--interval=15}';

    protected $description = 'Scan finalized USDT ranges from an explicitly initialized durable cursor';

    public function handle(ChainClientFactory $factory, ResilientScanner $scanner, MonitoringCycle $cycles, MonitoringReadiness $readiness, MonitoringIncidentService $incidents): int
    {
        $chain = (string) $this->argument('chain');
        $provider = (string) $this->option('provider');
        if (! in_array($chain, ['ethereum', 'tron'], true) || ! in_array($provider, ['primary', 'secondary'], true)) {
            $this->error('Choose a supported chain and provider role.');

            return self::FAILURE;
        }
        $range = filter_var($this->option('range'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        $interval = filter_var($this->option('interval'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3600]]);
        if ($range === false || $interval === false) {
            $this->error('Range and interval options are invalid.');

            return self::INVALID;
        }
        do {
            $idle = true;
            try {
                $state = $readiness->state($chain);
                if ($state !== 'ready') {
                    if ($state === 'safety_paused') {
                        $incidents->failed($chain, 'scanner_failed');
                    }
                    $this->line('Finalized monitoring is waiting for setup, resume, or a safety review.');
                    if ($this->option('once')) {
                        return self::FAILURE;
                    }
                } else {
                    $attempt = new CheckAttempt;
                    $primary = new CheckedChainClient($factory->make($chain, 'primary'), $attempt);
                    $secondary = config('blockchain.chains.'.$chain.'.providers.secondary.rpc_url')
                        ? new CheckedChainClient($factory->make($chain, 'secondary'), $attempt) : null;
                    $result = $cycles->run($chain, fn () => $provider === 'secondary' && $secondary !== null
                        ? $scanner->scanWithSecondary($primary, $secondary, $range)
                        : $scanner->scan($primary, $secondary, $range), $attempt);
                    $idle = $result->idle;
                    app(Heartbeat::class)->record('scan-'.$chain);
                    $this->line($idle ? 'No new finalized blocks.' : "Completed blocks {$result->fromBlock}-{$result->toBlock}: {$result->events} transfer events.");
                }
            } catch (Throwable $error) {
                $idle = true;
                if ($error instanceof ScanException && ! app()->isDownForMaintenance() && ! app(MaintenanceGuard::class)->isPaused()) {
                    try {
                        $incidents->failed($chain, 'scanner_failed');
                    } catch (Throwable) {
                        // Preserve the original scan failure if alert persistence fails.
                    }
                }
                // Upstream exception messages must never expose credential-bearing URLs or raw response bodies.
                $this->error('Finalized monitoring check did not complete. Inspect sanitized operational status.');

                if ($this->option('once')) {
                    return self::FAILURE;
                }
            }
            if (! $this->option('once')) {
                sleep($idle ? $interval : 1);
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }
}
