<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Blockchain\Exceptions\RpcException;
use App\Operations\MonitoringIncidentService;
use App\Reconciliation\ProviderCoolingDown;
use Illuminate\Support\Facades\Log;
use Throwable;

final class MonitoringCycle
{
    public function __construct(private readonly MonitoringIncidentService $incidents) {}

    public function run(string $monitor, callable $operation, CheckAttempt $attempt): mixed
    {
        try {
            $result = $operation();
        } catch (Throwable $error) {
            if ($error instanceof ProviderCoolingDown || ($attempt->attempted && ! $error instanceof ScanException)) {
                try {
                    if ($error instanceof ProviderCoolingDown) {
                        // This is a scheduled availability sample, not another
                        // HTTP request. The shared incident store debounces
                        // rapid restarts and parallel workers before counting.
                        $this->incidents->blocked($monitor);
                    } else {
                        $this->incidents->failed($monitor, $this->category($error));
                    }
                } catch (Throwable) {
                    // Alert persistence must not replace the original safe scan
                    // error or make reporting a prerequisite for chain recovery.
                    $this->reportStorageError();
                }
            }
            throw $error;
        }
        if ($attempt->attempted) {
            try {
                // A real checked idle head and successful fallback both count.
                $this->incidents->succeeded($monitor);
            } catch (Throwable) {
                // A completed scan must not be reported as failed because its
                // secondary notification store could not be updated.
                $this->reportStorageError();
            }
        }

        return $result;
    }

    private function category(Throwable $error): string
    {
        if ($error instanceof ProviderCoolingDown) {
            return 'api_unavailable';
        }
        if (! $error instanceof RpcException) {
            return 'scanner_failed';
        }
        if ($error->httpStatus === 429) {
            return 'rate_limited';
        }
        if (in_array($error->httpStatus, [401, 403], true)) {
            return 'configuration';
        }

        return $error->retryable ? 'api_unavailable' : 'invalid_response';
    }

    private function reportStorageError(): void
    {
        try {
            Log::warning('Monitoring incident status could not be persisted.');
        } catch (Throwable) {
            // A broken secondary log channel cannot mask the chain result.
        }
    }
}
