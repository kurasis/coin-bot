<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Blockchain\ChainClientFactory;
use App\Operations\Heartbeat;
use App\Operations\SanitizedFailure;
use App\Reconciliation\Reconciler;
use App\Reconciliation\ReconciliationResult;
use Illuminate\Console\Command;
use Throwable;

class ChainReconcile extends Command
{
    protected $signature = 'chain:reconcile {chain : ethereum or tron} {--once} {--interval=30} {--batch=25 : Maximum durable ranges per bounded head snapshot}';

    protected $description = 'Verify completed live ranges using independent finalized provider evidence';

    public function handle(ChainClientFactory $factory, Reconciler $reconciler): int
    {
        $chain = (string) $this->argument('chain');
        foreach (['primary', 'secondary'] as $role) {
            if (! config('blockchain.chains.'.$chain.'.providers.'.$role.'.rpc_url')) {
                $this->error('Both independent provider endpoints must be configured for reconciliation.');

                return self::FAILURE;
            }
        }
        $interval = filter_var($this->option('interval'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3600]]);
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($interval === false || $batch === false) {
            return self::INVALID;
        }
        do {
            try {
                $results = $reconciler->reconcileBatch($factory->make($chain), $factory->make($chain, 'secondary'), $this->option('once') ? 1 : $batch,
                    function (ReconciliationResult $result) use ($chain): void {
                        // Report each committed range immediately, even if a
                        // later range in this batch cannot finish.
                        app(Heartbeat::class)->record('reconcile-'.$chain);
                        $this->line(match (true) {
                            $result->waitingForHistory => 'Reconciliation is waiting for verified provider history settings.',
                            $result->waitingForFinality => 'Reconciliation is waiting for both providers to finalize the pending range.',
                            $result->idle => 'No completed finalized range is awaiting reconciliation.',
                            default => "Verified range {$result->rangeId}; recovered {$result->repairedEvents} events.",
                        });
                    });
                $result = $results[array_key_last($results)];
            } catch (Throwable $error) {
                $this->error('Reconciliation could not complete; inspect sanitized provider and conflict status.');
                $this->line(json_encode(['failure' => SanitizedFailure::details($error)], JSON_THROW_ON_ERROR));

                return self::FAILURE;
            }
            if (! $this->option('once')) {
                sleep($result->idle || $result->waitingForFinality || $result->waitingForHistory ? $interval : 1);
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }
}
