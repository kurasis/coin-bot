<?php

namespace App\Operations;

use App\Blockchain\Exceptions\RangeLimitException;
use App\Blockchain\Exceptions\RpcException;
use App\Monitoring\ScanException;
use App\Reconciliation\ProviderCoolingDown;
use Illuminate\Database\QueryException;
use Throwable;

/** Fixed categories only: never serialize exception messages, traces or requests. */
final class SanitizedFailure
{
    public static function details(Throwable $error): array
    {
        if ($error instanceof ProviderCoolingDown) {
            return ['reason' => 'provider_cooldown', 'retry_after_seconds' => $error->retryAfterSeconds];
        }
        if (! $error instanceof RpcException) {
            return ['reason' => match (true) {
                $error instanceof QueryException => 'database_operation_failed',
                $error instanceof ScanException => 'scan_validation_failed',
                default => 'internal_check_failed',
            }];
        }

        return ['reason' => self::reason($error), 'http_status' => $error->httpStatus,
            'retryable' => $error->retryable, 'retry_after_seconds' => $error->retryAfterSeconds];
    }

    private static function reason(RpcException $error): string
    {
        if ($error instanceof RangeLimitException) {
            return 'query_limit';
        }
        if ($error->httpStatus !== null) {
            return match (true) {
                in_array($error->httpStatus, [401, 403], true) => 'authentication_or_permission',
                $error->httpStatus === 429 => 'rate_limited',
                $error->httpStatus === 413 => 'request_too_large',
                $error->httpStatus === 400 => 'request_rejected',
                $error->httpStatus === 408 || $error->httpStatus >= 500 => 'upstream_unavailable',
                default => 'http_error',
            };
        }

        return match ($error->getMessage()) {
            'Blockchain endpoint is not Ethereum mainnet.', 'Blockchain endpoint is not TRON mainnet.' => 'wrong_network',
            'A valid HTTPS blockchain endpoint is required.' => 'invalid_endpoint_configuration',
            'Blockchain requests require PHP CLI URL streaming.' => 'php_url_streaming_disabled',
            'Configured blockchain TLS CA bundle is not readable.' => 'tls_ca_bundle_unreadable',
            'Blockchain connection failed.' => 'connection_failed',
            'Block is not available.', 'Solidified block is not available.' => 'head_not_available',
            'Blockchain endpoint reported an error.' => 'rpc_error',
            default => 'invalid_response',
        };
    }
}
