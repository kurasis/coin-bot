<?php

declare(strict_types=1);

namespace App\Reconciliation;

use App\Blockchain\Contracts\ChainClient;
use App\Blockchain\Exceptions\RpcException;
use App\Monitoring\BlockScanner;
use App\Monitoring\ScanException;
use App\Monitoring\ScanResult;
use Illuminate\Support\Facades\DB;

final class ResilientScanner
{
    public function __construct(
        private readonly BlockScanner $scanner = new BlockScanner,
        private readonly ProviderCircuitBreaker $circuits = new ProviderCircuitBreaker,
    ) {}

    public function scan(ChainClient $primary, ?ChainClient $secondary = null, int $maxRange = 100): ScanResult
    {
        $cursor = DB::table('scan_cursors')->where('chain', $primary->chain())->where('role', 'primary')->where('provider', $primary->provider())->first();
        if ($cursor === null) {
            throw new ScanException('Explicit primary scan initialization is required before failover.');
        }
        try {
            $this->circuits->assertAvailable($primary);
            $result = $this->scanner->scanCursor($primary, (int) $cursor->id, $maxRange);
            $this->circuits->succeeded($primary);

            return $result;
        } catch (RpcException $error) {
            $this->recordProviderFailure($primary, $error);
            if ($secondary === null) {
                throw $error;
            }
        } catch (ProviderCoolingDown $error) {
            if ($secondary === null) {
                throw $error;
            }
        }

        return $this->useSecondary($primary, $secondary, (int) $cursor->id, $maxRange);
    }

    public function scanWithSecondary(ChainClient $primary, ChainClient $secondary, int $maxRange = 100): ScanResult
    {
        $cursor = DB::table('scan_cursors')->where('chain', $primary->chain())->where('role', 'primary')->where('provider', $primary->provider())->first();
        if ($cursor === null) {
            throw new ScanException('Explicit primary scan initialization is required before switching providers.');
        }

        return $this->useSecondary($primary, $secondary, (int) $cursor->id, $maxRange);
    }

    private function useSecondary(ChainClient $primary, ChainClient $secondary, int $cursorId, int $maxRange): ScanResult
    {
        if ($secondary->chain() !== $primary->chain() || $secondary->provider() === $primary->provider()) {
            throw new ScanException('Failover requires a distinct provider for the same chain.');
        }
        $this->circuits->assertAvailable($secondary);
        try {
            // Continue the exact unfinished primary cursor, never create an independent writable head.
            $result = $this->scanner->scanCursor($secondary, $cursorId, $maxRange);
            $this->circuits->succeeded($secondary);

            return $result;
        } catch (RpcException $error) {
            $this->recordProviderFailure($secondary, $error);
            throw $error;
        }
    }

    private function recordProviderFailure(ChainClient $client, RpcException $error): void
    {
        // An independent provider may continue checking after authentication,
        // malformed-response, or wrong-network failures too. Its own network
        // and canonical evidence are still validated by BlockScanner. Only the
        // provider circuit uses a retryable envelope; scan errors stay intact.
        $this->circuits->failed($client, $error->retryable ? $error : new RpcException(
            'Blockchain provider is unavailable for monitoring.', true, $error->httpStatus, $error->retryAfterSeconds,
        ));
    }
}
