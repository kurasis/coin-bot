<?php

declare(strict_types=1);

namespace App\Blockchain;

use App\Blockchain\Exceptions\RpcException;

final class TronRpcClient extends EthereumRpcClient
{
    public const USDT_CONTRACT = '0xa614f803b6fd780986a42c78ec9c7f77e6ded13c';

    public function chain(): string
    {
        return 'tron';
    }

    public function verifyNetwork(): void
    {
        if (Hex::integer($this->transport->rpc('eth_chainId', [])) !== 728126428) {
            throw new RpcException('Blockchain endpoint is not TRON mainnet.');
        }
    }

    public function finalizedBlock(): array
    {
        return $this->parseNativeBlock($this->transport->native('/walletsolidity/getnowblock'));
    }

    public function block(int $number): array
    {
        if ($number < 0) {
            throw new RpcException('A blockchain quantity cannot be negative.');
        }
        $block = $this->parseNativeBlock($this->transport->native('/walletsolidity/getblockbynum', ['num' => $number]));
        if ($block['number'] !== $number) {
            throw new RpcException('Blockchain endpoint returned the wrong solidified block.');
        }

        return $block;
    }

    public function receipt(string $transactionHash): array
    {
        $transactionHash = Hex::data($transactionHash, 32);
        $raw = $this->transport->native('/walletsolidity/gettransactioninfobyid', ['value' => substr($transactionHash, 2)]);
        if ($raw === [] || ! isset($raw['id'], $raw['blockNumber'])) {
            throw new RpcException('Solidified transaction receipt is not available.', true);
        }

        return $this->normalizeNativeReceipt($raw, $this->block($this->nativeNumber($raw['blockNumber'])), $transactionHash);
    }

    /** @return list<array<string, mixed>> EVM-shaped receipts from one native solidified block. */
    public function solidifiedBlockReceipts(int $number): array
    {
        if ($number < 0) {
            throw new RpcException('A blockchain quantity cannot be negative.');
        }
        $nativeBlock = $this->transport->native('/walletsolidity/getblockbynum', ['num' => $number]);
        $block = $this->parseNativeBlock($nativeBlock);
        if ($block['number'] !== $number) {
            throw new RpcException('Blockchain endpoint returned the wrong solidified block.');
        }
        $receipts = $this->transport->native('/walletsolidity/gettransactioninfobyblocknum', ['num' => $number]);
        if (! array_is_list($receipts)) {
            throw new RpcException('Solidified block receipt response is not a list.');
        }

        $transactions = $nativeBlock['transactions'] ?? [];
        if (! is_array($transactions) || ! array_is_list($transactions)) {
            throw new RpcException('Invalid native block transaction list.');
        }
        $expectedHashes = [];
        foreach ($transactions as $transaction) {
            if (! is_array($transaction) || ! is_string($transaction['txID'] ?? null)) {
                throw new RpcException('Invalid native block transaction.');
            }
            $expectedHashes[] = Hex::data('0x'.$transaction['txID'], 32);
        }
        $normalized = array_map(fn (mixed $raw): array => $this->normalizeNativeReceipt($raw, $block), $receipts);
        $actualHashes = array_column($normalized, 'transactionHash');
        sort($expectedHashes);
        sort($actualHashes);
        if ($expectedHashes !== $actualHashes || count(array_unique($actualHashes)) !== count($actualHashes)) {
            throw new RpcException('Solidified block receipts are incomplete.', true);
        }

        return $normalized;
    }

    protected function contract(): string
    {
        return self::USDT_CONTRACT;
    }

    private function parseNativeBlock(array $raw): array
    {
        if (! is_string($raw['blockID'] ?? null) || ! isset($raw['block_header']['raw_data']['number'], $raw['block_header']['raw_data']['timestamp'])) {
            throw new RpcException('Solidified block is not available.', true);
        }

        return ['number' => $this->nativeNumber($raw['block_header']['raw_data']['number']), 'hash' => Hex::data('0x'.$raw['blockID'], 32), 'timestamp' => intdiv($this->nativeNumber($raw['block_header']['raw_data']['timestamp']), 1000)];
    }

    private function nativeNumber(mixed $number): int
    {
        if (! is_int($number) || $number < 0) {
            throw new RpcException('Invalid native blockchain number.');
        }

        return $number;
    }

    private function normalizeNativeReceipt(mixed $raw, array $block, ?string $expectedHash = null): array
    {
        if (! is_array($raw) || ! is_string($raw['id'] ?? null) || $this->nativeNumber($raw['blockNumber'] ?? null) !== $block['number']) {
            throw new RpcException('Invalid solidified transaction receipt.');
        }
        $hash = Hex::data('0x'.$raw['id'], 32);
        if ($expectedHash !== null && $hash !== $expectedHash) {
            throw new RpcException('Solidified receipt returned a different transaction.');
        }
        $nativeLogs = $raw['log'] ?? [];
        if (! is_array($nativeLogs) || ! array_is_list($nativeLogs)) {
            throw new RpcException('Invalid solidified receipt log list.');
        }
        $logs = [];
        foreach ($nativeLogs as $position => $log) {
            if (! is_array($log) || ! is_string($log['address'] ?? null) || ! is_string($log['data'] ?? null) || ! is_array($log['topics'] ?? null) || ! array_is_list($log['topics'])) {
                throw new RpcException('Invalid native transaction log.');
            }
            $logs[] = $this->validateLog([
                'address' => AddressCodec::toEvm('tron', '0x'.$log['address']),
                'data' => '0x'.$log['data'],
                'topics' => array_map(static fn (mixed $topic): string => is_string($topic) ? '0x'.$topic : '', $log['topics']),
                'transactionHash' => $hash,
                'blockHash' => $block['hash'],
                'blockNumber' => Hex::quantity($block['number']),
                'receiptLogPosition' => $position,
            ]);
        }
        $success = ($raw['receipt']['result'] ?? null) === 'SUCCESS' && ($raw['result'] ?? null) !== 'FAILED';
        // Non-contract successful native transactions may omit execution status; they have no logs.
        if (! $success && $logs !== [] && ! isset($raw['receipt']['result']) && ! isset($raw['result'])) {
            throw new RpcException('Solidified contract receipt has no execution result.');
        }

        return [
            'transactionHash' => $hash, 'blockHash' => $block['hash'], 'blockNumber' => Hex::quantity($block['number']),
            'status' => $success ? '0x1' : '0x0', 'logs' => $logs,
        ];
    }
}
