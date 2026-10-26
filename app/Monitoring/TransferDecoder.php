<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Blockchain\AddressCodec;
use App\Blockchain\Contracts\ChainClient;
use App\Blockchain\EthereumRpcClient;
use App\Blockchain\Hex;
use App\Blockchain\TronRpcClient;
use App\Domain\Amount;

final class TransferDecoder
{
    public function contract(string $chain): string
    {
        return match ($chain) {
            'ethereum' => EthereumRpcClient::USDT_CONTRACT,
            'tron' => TronRpcClient::USDT_CONTRACT,
            default => throw new ScanException('Unsupported blockchain.'),
        };
    }

    /** @return list<array<string, int|string>> */
    public function decode(ChainClient $client, array $candidates, array $watched, int $from, int $to): array
    {
        $transactions = [];
        foreach ($candidates as $candidate) {
            if (! is_array($candidate) || ! $this->isTransfer($client->chain(), $candidate)) {
                continue;
            }
            $hash = Hex::data($candidate['transactionHash'] ?? null, 32);
            $transactions[$hash][] = $candidate;
        }
        $events = [];
        $blocks = [];
        foreach ($transactions as $hash => $discoveries) {
            $receipt = $client->receipt($hash);
            if (Hex::data($receipt['transactionHash'] ?? null, 32) !== $hash || Hex::integer($receipt['status'] ?? null) !== 1) {
                throw new ScanException('A discovered transfer has no successful matching receipt.');
            }
            $height = Hex::integer($receipt['blockNumber'] ?? null);
            $blockHash = Hex::data($receipt['blockHash'] ?? null, 32);
            if ($height < $from || $height > $to || ! is_array($receipt['logs'] ?? null) || ! array_is_list($receipt['logs'])) {
                throw new ScanException('Receipt is outside the complete requested range.');
            }
            $block = $blocks[$height] ??= $client->block($height);
            if (($block['number'] ?? null) !== $height || ($block['hash'] ?? null) !== $blockHash || ! is_int($block['timestamp'] ?? null) || $block['timestamp'] < 0) {
                throw new ScanException('Receipt does not match its canonical timestamped block.');
            }
            foreach ($discoveries as $discovery) {
                if (Hex::integer($discovery['blockNumber'] ?? null) !== $height || Hex::data($discovery['blockHash'] ?? null, 32) !== $blockHash) {
                    throw new ScanException('Discovered log and receipt disagree on block inclusion.');
                }
                $found = false;
                foreach ($receipt['logs'] as $log) {
                    if (is_array($log) && $this->sameLog($log, $discovery)) {
                        $found = true;
                        break;
                    }
                }
                if (! $found) {
                    throw new ScanException('A discovered transfer is missing from its receipt.');
                }
            }
            foreach ($receipt['logs'] as $position => $log) {
                if (! is_array($log) || ! $this->isTransfer($client->chain(), $log)) {
                    continue;
                }
                if (($log['removed'] ?? false) !== false || count($log['topics']) !== 3) {
                    throw new ScanException('Official Transfer event is malformed or removed.');
                }
                $sender = $this->addressFromTopic($client->chain(), $log['topics'][1]);
                $recipient = $this->addressFromTopic($client->chain(), $log['topics'][2]);
                if (! in_array($sender, $watched, true) && ! in_array($recipient, $watched, true)) {
                    continue;
                }
                $amount = Amount::fromHex(Hex::data($log['data'] ?? null, 32));
                $events[] = [
                    'chain' => $client->chain(), 'transaction_hash' => $hash, 'receipt_log_position' => $position,
                    'contract_address' => AddressCodec::normalize($client->chain(), $this->contract($client->chain())),
                    'from_address' => $sender, 'to_address' => $recipient, 'amount_raw' => $amount->raw(),
                    'block_number' => $height, 'block_hash' => $blockHash, 'block_timestamp' => $block['timestamp'],
                    'finality' => $client->chain() === 'tron' ? 'solidified' : 'finalized',
                ];
            }
        }
        usort($events, static fn (array $a, array $b): int => [$a['block_number'], $a['transaction_hash'], $a['receipt_log_position']] <=> [$b['block_number'], $b['transaction_hash'], $b['receipt_log_position']]);

        return $events;
    }

    private function isTransfer(string $chain, array $log): bool
    {
        return is_string($log['address'] ?? null)
            && strtolower($log['address']) === $this->contract($chain)
            && is_array($log['topics'] ?? null)
            && strtolower($log['topics'][0] ?? '') === EthereumRpcClient::TRANSFER_TOPIC;
    }

    private function sameLog(array $a, array $b): bool
    {
        return strtolower($a['address'] ?? '') === strtolower($b['address'] ?? '')
            && ($a['topics'] ?? null) === ($b['topics'] ?? null)
            && strtolower($a['data'] ?? '') === strtolower($b['data'] ?? '');
    }

    private function addressFromTopic(string $chain, mixed $topic): string
    {
        $topic = Hex::data($topic, 32);
        if (substr($topic, 2, 24) !== str_repeat('0', 24)) {
            throw new ScanException('Transfer address topic has invalid padding.');
        }

        return AddressCodec::normalize($chain, '0x'.substr($topic, -40));
    }
}
