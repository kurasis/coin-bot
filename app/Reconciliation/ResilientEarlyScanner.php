<?php

declare(strict_types=1);

namespace App\Reconciliation;

use App\Blockchain\Contracts\ChainClient;
use App\Blockchain\Exceptions\RpcException;
use App\Monitoring\EarlyWitnessException;
use App\Monitoring\EthereumEarlyScanner;
use App\Monitoring\ScanException;
use App\Monitoring\ScanResult;

final class ResilientEarlyScanner
{
    public function __construct(
        private readonly EthereumEarlyScanner $scanner = new EthereumEarlyScanner,
        private readonly ProviderCircuitBreaker $circuits = new ProviderCircuitBreaker,
    ) {}

    public function scan(ChainClient $primary, ?ChainClient $secondary = null, int $maxRange = 100): ScanResult
    {
        if (! config('ethereum.early_enabled', false)) {
            return new ScanResult(0, null, null, 0, true);
        }
        if ($primary->chain() !== 'ethereum'
            || ($secondary !== null && ($secondary->chain() !== 'ethereum' || $secondary->provider() === $primary->provider()))) {
            throw new ScanException('Early Ethereum failover needs distinct providers on the same chain.');
        }
        try {
            $this->circuits->assertAvailable($primary);
            $result = $this->scanner->scan($primary, $maxRange, $this->availableWitness($secondary));
            $this->circuits->succeeded($primary);

            return $result;
        } catch (EarlyWitnessException $error) {
            $this->recordWitnessFailure($secondary, $error);
            throw $error;
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
        $this->circuits->assertAvailable($secondary);
        try {
            $result = $this->scanner->scan($secondary, $maxRange, $this->availableWitness($primary));
            $this->circuits->succeeded($secondary);

            return $result;
        } catch (EarlyWitnessException $error) {
            $this->recordWitnessFailure($primary, $error);
            throw $error;
        } catch (RpcException $error) {
            $this->recordProviderFailure($secondary, $error);
            throw $error;
        }
    }

    private function recordProviderFailure(ChainClient $client, RpcException $error): void
    {
        $this->circuits->failed($client, $error->retryable ? $error : new RpcException(
            'Ethereum provider is unavailable for early monitoring.', true, $error->httpStatus, $error->retryAfterSeconds,
        ));
    }

    private function availableWitness(?ChainClient $client): ?ChainClient
    {
        if ($client === null) {
            return null;
        }
        try {
            $this->circuits->assertAvailable($client);

            return $client;
        } catch (ProviderCoolingDown) {
            // A reorg must wait for independent evidence; it must not spend a
            // provider's mandatory cooldown merely to obtain that evidence.
            return null;
        }
    }

    private function recordWitnessFailure(?ChainClient $client, EarlyWitnessException $error): void
    {
        if ($client !== null && $client->provider() === $error->provider) {
            $this->recordProviderFailure($client, new RpcException(
                'Independent Ethereum reorganization evidence is unavailable.',
                $error->retryable, $error->httpStatus, $error->retryAfterSeconds,
            ));
        }
    }
}
