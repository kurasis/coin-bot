<?php

namespace App\Wallets;

use App\Access\AccessService;
use App\Blockchain\AddressCodec;
use App\Blockchain\ChainClientFactory;
use App\Domain\Amount;
use App\Domain\UserScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class WalletService
{
    public function __construct(
        private readonly AccessService $access,
        private readonly UserScope $scope,
        private readonly ChainClientFactory $clients,
        private readonly BackfillService $backfills,
    ) {}

    public function add(
        int $userId,
        string $chain,
        string $address,
        string $label = '',
        ?int $startBlock = null,
        string $direction = 'both',
        string $minAmount = '0',
    ): object {
        $this->access->requireGrant($userId);
        $prepared = $this->prepare($chain, $address, $label, $startBlock, $direction, $minAmount);
        $existing = $this->existing($userId, $prepared['chain'], $prepared['address']);
        if ($existing !== null) {
            return $existing;
        }
        $prepared['start_block'] ??= $this->defaultStart($chain);

        return DB::transaction(fn (): object => $this->addPrepared($userId, $prepared), 3);
    }

    /** Validate the entire batch before writing; repeated rows preserve existing private settings. */
    public function importBatch(int $userId, array $records): Collection
    {
        $this->access->requireGrant($userId);
        if ($records === [] || count($records) > 100) {
            throw new InvalidArgumentException('An import must contain between one and 100 wallet records.');
        }
        $prepared = [];
        $defaultStarts = [];
        foreach ($records as $record) {
            if (! is_array($record) || ! isset($record['chain'], $record['address'])
                || ! is_string($record['chain']) || ! is_string($record['address'])) {
                throw new InvalidArgumentException('Every wallet record needs a chain and address.');
            }
            $allowed = ['chain', 'address', 'label', 'start_block', 'direction', 'min_amount'];
            if (array_diff(array_keys($record), $allowed) !== []) {
                throw new InvalidArgumentException('Unknown wallet record field.');
            }
            $start = $record['start_block'] ?? null;
            if ($start !== null && (! is_int($start) && (! is_string($start) || ! preg_match('/^[0-9]+$/D', $start)))) {
                throw new InvalidArgumentException('The start block must be a nonnegative integer.');
            }
            if ($start !== null && (strlen((string) $start) > strlen((string) PHP_INT_MAX)
                || (strlen((string) $start) === strlen((string) PHP_INT_MAX) && strcmp((string) $start, (string) PHP_INT_MAX) > 0))) {
                throw new InvalidArgumentException('The start block is too large.');
            }
            foreach (['label', 'direction', 'min_amount'] as $field) {
                if (isset($record[$field]) && ! is_string($record[$field])) {
                    throw new InvalidArgumentException('Wallet settings must use string values.');
                }
            }
            $item = $this->prepare($record['chain'], $record['address'], $record['label'] ?? '', $start === null ? null : (int) $start,
                $record['direction'] ?? 'both', $record['min_amount'] ?? '0');
            if ($item['start_block'] === null && $this->existing($userId, $item['chain'], $item['address']) === null) {
                $defaultStarts[$item['chain']] ??= $this->defaultStart($item['chain']);
                $item['start_block'] = $defaultStarts[$item['chain']];
            }
            $prepared[] = $item;
        }

        return DB::transaction(function () use ($userId, $prepared): Collection {
            $this->access->requireGrant($userId);

            return collect($prepared)->map(fn (array $item): object => $this->addPrepared($userId, $item));
        }, 3);
    }

    public function update(int $userId, int $userWalletId, array $settings): object
    {
        $allowed = ['label', 'enabled', 'direction', 'min_amount'];
        if (array_diff(array_keys($settings), $allowed) !== []) {
            throw new InvalidArgumentException('Unknown wallet setting.');
        }
        if (isset($settings['label'])) {
            $this->validateLabel($settings['label']);
        }
        if (isset($settings['enabled']) && ! is_bool($settings['enabled'])) {
            throw new InvalidArgumentException('Enabled must be a boolean.');
        }
        if (isset($settings['direction'])) {
            $this->validateDirection($settings['direction']);
        }
        if (isset($settings['min_amount'])) {
            $settings['min_amount'] = (new Amount($settings['min_amount']))->raw();
        }

        return DB::transaction(function () use ($userId, $userWalletId, $settings): object {
            $this->access->requireGrant($userId);
            $wallet = $this->scope->wallet($userId, $userWalletId);
            $walletUpdates = array_intersect_key($settings, array_flip(['label', 'enabled']));
            if ($walletUpdates !== []) {
                DB::table('user_wallets')->where('id', $userWalletId)->where('user_id', $userId)
                    ->update($walletUpdates + ['updated_at' => now()]);
            }
            $subscriptionUpdates = array_intersect_key($settings, array_flip(['direction', 'min_amount', 'enabled']));
            if ($subscriptionUpdates !== []) {
                DB::table('wallet_subscriptions')->where('user_id', $userId)->where('user_wallet_id', $userWalletId)
                    ->update($subscriptionUpdates + ['updated_at' => now()]);
            }
            if (array_key_exists('enabled', $settings) && $settings['enabled'] !== (bool) $wallet->enabled) {
                $height = $this->cutover($wallet->chain, (int) $wallet->start_block);
                if ($settings['enabled']) {
                    $this->raiseNotificationFloor($userId, $wallet->chain, $height + 1, $userWalletId);
                }
            }
            if (($settings['enabled'] ?? true) === false) {
                $subscriptionIds = DB::table('wallet_subscriptions')->where('user_id', $userId)
                    ->where('user_wallet_id', $userWalletId)->pluck('id');
                DB::table('outbox_authorizations')->where('user_id', $userId)->whereIn('subscription_id', $subscriptionIds)->delete();
            }
            $this->audit($userId, 'wallet.updated', $userWalletId);

            return $this->scope->wallet($userId, $userWalletId);
        }, 3);
    }

    public function disable(int $userId, int $userWalletId): object
    {
        return $this->update($userId, $userWalletId, ['enabled' => false]);
    }

    public function wallets(int $userId): Collection
    {
        $this->access->requireGrant($userId);

        return $this->scope->wallets($userId);
    }

    /** Establish only a user's own private destination; arbitrary chat IDs are never accepted. */
    public function ensurePrivateDestination(int $userId): int
    {
        return DB::transaction(function () use ($userId): int {
            $this->access->requireGrant($userId);
            $user = DB::table('users')->where('id', $userId)->first();
            if ($user === null || ! preg_match('/^[1-9][0-9]{0,18}$/D', $user->telegram_user_id)) {
                throw new InvalidArgumentException('A valid enrolled Telegram identity is required.');
            }
            DB::table('chat_destinations')->insertOrIgnore([
                'chat_id' => $user->telegram_user_id, 'kind' => 'private',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $destination = DB::table('chat_destinations')->where('chat_id', $user->telegram_user_id)->first();
            if ($destination === null || $destination->kind !== 'private') {
                throw new InvalidArgumentException('The private destination cannot be bound.');
            }
            DB::table('user_chat_destinations')->insertOrIgnore([
                'user_id' => $userId, 'destination_id' => $destination->id, 'enabled' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return (int) $destination->id;
        }, 3);
    }

    /** Restore active scan membership after a new key activation without replaying old ranges. */
    public function refreshWatchSetsForUser(int $userId, bool $resetBoundary = true): void
    {
        DB::transaction(function () use ($userId, $resetBoundary): void {
            $this->access->requireGrant($userId);
            $chains = DB::table('user_wallets as uw')->join('wallets as w', 'w.id', '=', 'uw.wallet_id')
                ->where('uw.user_id', $userId)->where('uw.enabled', true)
                ->select('w.chain')->selectRaw('MIN(uw.start_block) AS initial_start')->groupBy('w.chain')->get();
            foreach ($chains as $chain) {
                $height = $this->cutover($chain->chain, (int) $chain->initial_start);
                if ($resetBoundary) {
                    $floor = $height + 1;
                    if ($chain->chain === 'ethereum' && config('ethereum.early_enabled', false)) {
                        // Enrollment holds a database transaction: use the last
                        // durable early boundary, never perform RPC while locked.
                        $early = DB::table('ethereum_early_state')->where('id', 1)->value('last_block');
                        if ($early !== null) {
                            $floor = max($floor, (int) $early + 1);
                        }
                    }
                    $this->raiseNotificationFloor($userId, $chain->chain, $floor);
                }
            }
        }, 3);
    }

    private function addPrepared(int $userId, array $prepared): object
    {
        $this->access->requireGrant($userId);
        $existing = $this->existing($userId, $prepared['chain'], $prepared['address']);
        if ($existing !== null) {
            return $existing;
        }
        $destinationId = $this->ensurePrivateDestination($userId);
        DB::table('wallets')->insertOrIgnore([
            'chain' => $prepared['chain'], 'address' => $prepared['address'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        $walletId = (int) DB::table('wallets')->where('chain', $prepared['chain'])->where('address', $prepared['address'])->value('id');
        $userWalletId = DB::table('user_wallets')->insertGetId([
            'user_id' => $userId, 'wallet_id' => $walletId, 'label' => $prepared['label'],
            'start_block' => $prepared['start_block'], 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('wallet_subscriptions')->insert([
            'user_id' => $userId, 'user_wallet_id' => $userWalletId, 'destination_id' => $destinationId,
            'direction' => $prepared['direction'], 'min_amount' => $prepared['min_amount'],
            'history_policy' => 'silent', 'notification_start_block' => $prepared['start_block'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cutover = $this->cutover($prepared['chain'], $prepared['start_block']);
        $this->raiseNotificationFloor($userId, $prepared['chain'], $cutover + 1, (int) $userWalletId);
        if ($prepared['start_block'] <= $cutover) {
            $this->backfills->create($userId, (int) $userWalletId, $prepared['start_block'], $cutover, false);
        }
        $this->audit($userId, 'wallet.added', (int) $userWalletId);

        return $this->scope->wallet($userId, (int) $userWalletId);
    }

    private function raiseNotificationFloor(int $userId, string $chain, int $floor, ?int $onlyUserWalletId = null): void
    {
        $wallets = DB::table('user_wallets as uw')->join('wallets as w', 'w.id', '=', 'uw.wallet_id')
            ->where('uw.user_id', $userId)->where('uw.enabled', true)->where('w.chain', $chain);
        if ($onlyUserWalletId !== null) {
            $wallets->where('uw.id', $onlyUserWalletId);
        }
        DB::table('wallet_subscriptions')->where('user_id', $userId)->whereIn('user_wallet_id', $wallets->select('uw.id'))
            ->where('notification_start_block', '<', $floor)
            ->update(['notification_start_block' => $floor, 'updated_at' => now()]);
    }

    private function cutover(string $chain, int $initialStart): int
    {
        $cursors = DB::table('scan_cursors')->where('chain', $chain)->where('role', 'primary')->get();
        $heights = $cursors->pluck('last_block')->unique();
        if ($heights->count() > 1) {
            throw new InvalidArgumentException('Primary cursor cutover requires a common boundary.');
        }
        $height = $cursors->isEmpty() ? $initialStart - 1 : (int) $cursors->first()->last_block;
        $watchSetId = DB::table('watch_sets')->insertGetId([
            'chain' => $chain, 'version' => ((int) DB::table('watch_sets')->where('chain', $chain)->max('version')) + 1,
            'cutover_block' => $height, 'activated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $walletIds = DB::table('wallets as w')->join('user_wallets as uw', 'uw.wallet_id', '=', 'w.id')
            ->join('users as u', 'u.id', '=', 'uw.user_id')->join('access_grants as g', 'g.user_id', '=', 'u.id')
            ->join('access_keys as k', 'k.id', '=', 'g.access_key_id')
            ->where('w.chain', $chain)->where('uw.enabled', true)->where('u.state', 'active')
            ->whereNull('g.revoked_at')->whereNull('k.revoked_at')
            ->where(fn (Builder $query) => $query->whereNull('g.expires_at')->orWhere('g.expires_at', '>', now()))
            ->distinct()->pluck('w.id');
        foreach ($walletIds as $walletId) {
            DB::table('watch_set_wallets')->insert(['watch_set_id' => $watchSetId, 'wallet_id' => $walletId]);
        }
        if ($cursors->isEmpty()) {
            DB::table('scan_cursors')->insert([
                'chain' => $chain, 'role' => 'primary', 'provider' => 'primary', 'watch_set_id' => $watchSetId,
                'last_block' => $height, 'created_at' => now(), 'updated_at' => now(),
            ]);
        } else {
            DB::table('scan_cursors')->whereIn('id', $cursors->pluck('id'))->update([
                'watch_set_id' => $watchSetId, 'updated_at' => now(),
            ]);
        }

        return $height;
    }

    private function prepare(string $chain, string $address, string $label, ?int $startBlock, string $direction, string $minAmount): array
    {
        $this->validateLabel($label);
        $this->validateDirection($direction);
        if ($startBlock !== null && $startBlock < 0) {
            throw new InvalidArgumentException('The start block must be nonnegative.');
        }

        return ['chain' => $chain, 'address' => AddressCodec::normalize($chain, $address), 'label' => $label,
            'start_block' => $startBlock, 'direction' => $direction, 'min_amount' => (new Amount($minAmount))->raw()];
    }

    private function validateLabel(string $label): void
    {
        if (! mb_check_encoding($label, 'UTF-8') || mb_strlen($label) > 120 || preg_match('/[\x00-\x1F\x7F]/u', $label)) {
            throw new InvalidArgumentException('A label must be at most 120 characters without control characters.');
        }
    }

    private function validateDirection(string $direction): void
    {
        if (! in_array($direction, ['both', 'incoming', 'outgoing'], true)) {
            throw new InvalidArgumentException('Direction must be both, incoming, or outgoing.');
        }
    }

    private function defaultStart(string $chain): int
    {
        $client = $this->clients->make($chain);
        $client->verifyNetwork();

        if ($chain === 'ethereum' && config('ethereum.early_enabled', false)) {
            if (! method_exists($client, 'latestBlock')) {
                throw new InvalidArgumentException('The selected Ethereum client cannot provide an early monitoring boundary.');
            }
            $depth = max(1, min(64, (int) config('ethereum.confirmations', 6)));

            return max(0, $client->latestBlock()['number'] - $depth) + 1;
        }

        return $client->finalizedBlock()['number'] + 1;
    }

    private function existing(int $userId, string $chain, string $address): ?object
    {
        return DB::table('user_wallets')->join('wallets', 'wallets.id', '=', 'user_wallets.wallet_id')
            ->where('user_wallets.user_id', $userId)->where('wallets.chain', $chain)->where('wallets.address', $address)
            ->select('user_wallets.*', 'wallets.chain', 'wallets.address')->first();
    }

    private function audit(int $userId, string $action, int $subjectId): void
    {
        DB::table('audit_events')->insert(['actor_user_id' => $userId, 'action' => $action,
            'subject_type' => 'user_wallet', 'subject_id' => (string) $subjectId, 'created_at' => now(), 'updated_at' => now()]);
    }
}
