<?php

declare(strict_types=1);

namespace App\Blockchain;

use App\Blockchain\Contracts\ChainClient;
use App\Blockchain\Exceptions\RpcException;

final class ChainClientFactory
{
    public function make(string $chain, string $provider = 'primary'): ChainClient
    {
        if (! in_array($chain, ['ethereum', 'tron'], true) || ! in_array($provider, ['primary', 'secondary'], true)) {
            throw new RpcException('Unknown blockchain or provider role.');
        }
        $settings = config('blockchain.chains.'.$chain.'.providers.'.$provider);
        if (! is_array($settings)) {
            throw new RpcException('Blockchain provider configuration is missing.');
        }
        $transport = new RpcTransport($settings, $chain, $provider);

        return $chain === 'ethereum'
            ? new EthereumRpcClient($transport, $provider, $settings)
            : new TronRpcClient($transport, $provider, $settings);
    }
}
