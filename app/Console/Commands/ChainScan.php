<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Blockchain\ChainClientFactory;
use App\Operations\Heartbeat;
use App\Reconciliation\ResilientScanner;
use Illuminate\Console\Command;
use Throwable;

class ChainScan extends Command
{
    protected $signature = 'chain:scan {chain : ethereum or tron} {--once} {--provider=primary} {--range=100} {--interval=15}';

    protected $description = 'Scan finalized USDT ranges from an explicitly initialized durable cursor';

    public function handle(ChainClientFactory $factory, ResilientScanner $scanner): int
    {
        $chain = (string) $this->argument('chain');
        $provider = (string) $this->option('provider');
        $settings = config('blockchain.chains.'.$chain.'.providers.'.$provider);
        if (! is_array($settings) || ! is_string($settings['rpc_url'] ?? null) || $settings['rpc_url'] === '') {
            $this->error('The selected chain/provider is not configured.');

            return self::FAILURE;
        }
        $range = filter_var($this->option('range'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        $interval = filter_var($this->option('interval'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3600]]);
        if ($range === false || $interval === false) {
            $this->error('Range and interval options are invalid.');

            return self::INVALID;
        }
        $primary = $factory->make($chain, 'primary');
        $secondary = config('blockchain.chains.'.$chain.'.providers.secondary.rpc_url') ? $factory->make($chain, 'secondary') : null;
        do {
            try {
                $result = $provider === 'secondary' && $secondary !== null
                    ? $scanner->scanWithSecondary($primary, $secondary, $range)
                    : $scanner->scan($primary, $secondary, $range);
                if (class_exists(Heartbeat::class)) {
                    app(Heartbeat::class)->record('scan-'.$chain);
                }
                $this->line($result->idle ? 'No new finalized blocks.' : "Completed blocks {$result->fromBlock}-{$result->toBlock}: {$result->events} transfer events.");
            } catch (Throwable) {
                // Upstream exception messages must never expose credential-bearing URLs or raw response bodies.
                $this->error('Finalized scan failed; durable coverage was not advanced. Inspect sanitized operational status.');

                return self::FAILURE;
            }
            if (! $this->option('once')) {
                sleep($result->idle ? $interval : 1);
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }
}
