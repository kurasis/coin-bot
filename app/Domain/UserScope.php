<?php

namespace App\Domain;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** User-scoped reads and writes. Callers must separately require an active access grant. */
final class UserScope
{
    public function wallets(int $userId): Collection
    {
        return DB::table('user_wallets')
            ->join('wallets', 'wallets.id', '=', 'user_wallets.wallet_id')
            ->where('user_wallets.user_id', $userId)
            ->select('user_wallets.*', 'wallets.chain', 'wallets.address')
            ->orderBy('user_wallets.id')
            ->get();
    }

    public function wallet(int $userId, int $userWalletId): object
    {
        $wallet = DB::table('user_wallets')
            ->join('wallets', 'wallets.id', '=', 'user_wallets.wallet_id')
            ->where('user_wallets.user_id', $userId)
            ->where('user_wallets.id', $userWalletId)
            ->select('user_wallets.*', 'wallets.chain', 'wallets.address')
            ->first();

        if ($wallet === null) {
            throw (new ModelNotFoundException)->setModel('UserWallet');
        }

        return $wallet;
    }

    public function renameWallet(int $userId, int $userWalletId, string $label): void
    {
        if (mb_strlen($label) > 120 || preg_match('/[\x00-\x1F\x7F]/u', $label)) {
            throw new InvalidArgumentException('A wallet label must be at most 120 characters without control characters.');
        }

        $this->wallet($userId, $userWalletId);

        DB::table('user_wallets')->where('id', $userWalletId)->where('user_id', $userId)
            ->update(['label' => $label, 'updated_at' => now()]);
    }

    public function subscriptions(int $userId): Collection
    {
        return DB::table('wallet_subscriptions')->where('user_id', $userId)->orderBy('id')->get();
    }

    public function destinations(int $userId): Collection
    {
        return DB::table('user_chat_destinations')
            ->join('chat_destinations', 'chat_destinations.id', '=', 'user_chat_destinations.destination_id')
            ->where('user_chat_destinations.user_id', $userId)
            ->where('user_chat_destinations.enabled', true)
            ->whereNull('user_chat_destinations.revoked_at')
            ->select('chat_destinations.*')
            ->orderBy('chat_destinations.id')
            ->get();
    }

    public function transferHistory(int $userId, int $limit = 100): Collection
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('History limit must be between one and 500.');
        }

        return DB::table('transfers')
            ->join('wallets', function (JoinClause $join): void {
                $join->on('wallets.chain', '=', 'transfers.chain')
                    ->where(function (JoinClause $match): void {
                        $match->on('wallets.address', '=', 'transfers.from_address')
                            ->orOn('wallets.address', '=', 'transfers.to_address');
                    });
            })
            ->join('user_wallets', 'user_wallets.wallet_id', '=', 'wallets.id')
            ->where('user_wallets.user_id', $userId)
            ->whereColumn('transfers.block_number', '>=', 'user_wallets.start_block')
            ->select('transfers.*')
            ->distinct()
            ->orderByDesc('transfers.block_number')
            ->orderByDesc('transfers.id')
            ->limit($limit)
            ->get();
    }
}
