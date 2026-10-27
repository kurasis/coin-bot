<?php

namespace App\Telegram;

use App\Domain\Amount;
use Illuminate\Support\Collection;

final class MessageFormatter
{
    /** Labels are selected only from the revalidated routes of this exact destination. */
    public function format(object $transfer, object $destination, Collection $routes): string
    {
        $labels = $destination->kind === 'private'
            ? $routes->pluck('label')->filter(fn ($label) => is_string($label) && $label !== '')->unique()->values()->all()
            : [];
        $label = $destination->kind !== 'private' ? $destination->approved_label : implode(', ', $labels);
        $label = $this->clean($label ?: 'Watched wallet');
        $incoming = $routes->contains(fn ($route) => $route->address === $transfer->to_address);
        $outgoing = $routes->contains(fn ($route) => $route->address === $transfer->from_address);
        $direction = $incoming && $outgoing ? 'Internal / self transfer' : ($incoming ? 'Incoming' : 'Outgoing');
        $hash = $transfer->transaction_hash;
        $explorer = $transfer->chain === 'ethereum'
            ? 'https://etherscan.io/tx/'.$hash
            : 'https://tronscan.org/#/transaction/'.preg_replace('/^0x/', '', $hash);
        $identity = $transfer->chain.':'.$hash.':'.$transfer->receipt_log_position;
        if (($transfer->finality ?? null) === 'preliminary') {
            $identity .= ':'.$transfer->block_hash;
        }
        $id = substr(hash('sha256', $identity), 0, 16);

        if (($transfer->notification_kind ?? null) === 'reorg') {
            return "Ethereum preliminary observation invalidated\n"
                ."The earlier preliminary block observation is no longer canonical.\n"
                ."The transaction may appear in another block.\n"
                .'Observed block: '.$transfer->block_number.' ('.$transfer->block_hash.")\n"
                .'Event: '.$id."\n".$explorer;
        }
        $status = $transfer->chain === 'tron' ? 'Solidified' : 'Finalized';
        if (($transfer->finality ?? null) === 'preliminary') {
            $status = 'Preliminary ('.(int) ($transfer->confirmations ?? 6).' subsequent blocks; not finalized)';
        }

        return $direction.' USDT'."\n"
            .$label."\n"
            .'Amount: '.(new Amount($transfer->amount_raw))->format(6).' USDT'."\n"
            .'Network: '.($transfer->chain === 'ethereum' ? 'Ethereum' : 'TRON')."\n"
            .'From: '.$transfer->from_address."\n"
            .'To: '.$transfer->to_address."\n"
            .'Block time: '.gmdate('Y-m-d H:i:s', (int) $transfer->block_timestamp).' UTC'."\n"
            .(($transfer->finality ?? null) === 'preliminary' ? 'Observed block: '.$transfer->block_number.' ('.$transfer->block_hash.")\n" : '')
            .'Status: '.$status."\n"
            .'Event: '.$id."\n".$explorer;
    }

    private function clean(string $value): string
    {
        return mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', ' ', $value) ?? '', 0, 240);
    }
}
