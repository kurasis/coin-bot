<?php

namespace App\Operations;

use App\Access\AccessService;
use App\Telegram\TelegramAudience;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class MonitoringIncidentService
{
    public function __construct(private AccessService $access, private TelegramAudience $audience) {}

    /** Count scheduled unavailable checks, debounced across workers/restarts. */
    public function failed(string $chain, string $category = 'api_unavailable'): void
    {
        $this->checkChain($chain);
        $category = in_array($category, ['api_unavailable', 'rate_limited', 'invalid_response', 'configuration', 'scanner_failed'], true)
            ? $category : 'api_unavailable';
        DB::transaction(function () use ($chain, $category): void {
            DB::table('monitoring_incidents')->insertOrIgnore([
                'chain' => $chain, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $incident = DB::table('monitoring_incidents')->where('chain', $chain)->first();
            if ($incident->last_counted_failure_at !== null
                && CarbonImmutable::parse($incident->last_counted_failure_at)->diffInSeconds(now(), false) < $this->checkInterval()) {
                DB::table('monitoring_incidents')->where('id', $incident->id)->update([
                    'last_failure_at' => now(), 'error_category' => $category, 'updated_at' => now(),
                ]);
                if ($incident->state === 'outage') {
                    $this->enqueueOutage(DB::table('monitoring_incidents')->find($incident->id));
                }

                return;
            }
            $count = min(2147483647, (int) $incident->consecutive_failures + 1);
            $sampleAt = now()->format('Y-m-d H:i:s.u');
            $first = $incident->first_failure_at ?? $sampleAt;
            $elapsed = (int) CarbonImmutable::parse($first)->diffInSeconds(now(), false);
            $open = $incident->state === 'outage'
                || ($count >= $this->threshold() && $elapsed >= $this->minimumDuration());
            $newOutage = $open && $incident->state !== 'outage';
            DB::table('monitoring_incidents')->where('id', $incident->id)->update([
                'state' => $open ? 'outage' : 'healthy',
                'consecutive_failures' => $count, 'error_category' => $category,
                'first_failure_at' => $first, 'last_failure_at' => now(),
                'last_counted_failure_at' => $sampleAt,
                'generation' => (int) $incident->generation + ($newOutage ? 1 : 0),
                'opened_at' => $newOutage ? now() : $incident->opened_at,
                'recovered_at' => $newOutage ? null : $incident->recovered_at,
                'updated_at' => now(),
            ]);
            if ($open) {
                $this->enqueueOutage(DB::table('monitoring_incidents')->find($incident->id));
            }
        }, 3);
    }

    /** A known Retry-After/circuit wait still means the monitor cannot check. */
    public function blocked(string $chain): void
    {
        $this->checkChain($chain);
        $category = DB::table('monitoring_incidents')->where('chain', $chain)->value('error_category');
        $this->failed($chain, is_string($category) ? $category : 'api_unavailable');
    }

    /** A checked idle head succeeds; known unavailability uses blocked(). */
    public function succeeded(string $chain): void
    {
        $this->checkChain($chain);
        DB::transaction(function () use ($chain): void {
            $incident = DB::table('monitoring_incidents')->where('chain', $chain)->first();
            if ($incident === null) {
                return;
            }
            DB::table('monitoring_incidents')->where('id', $incident->id)->update([
                'state' => 'healthy', 'consecutive_failures' => 0,
                'first_failure_at' => null, 'last_failure_at' => null,
                'last_counted_failure_at' => null,
                'error_category' => null,
                'recovered_at' => $incident->state === 'outage' ? now() : $incident->recovered_at,
                'updated_at' => now(),
            ]);
            if ($incident->state === 'outage') {
                // Do not invalidate an HTTP request already in flight. Its
                // completion will record whether a recovery message is needed.
                DB::table('monitoring_alerts')->where('incident_id', $incident->id)
                    ->where('generation', $incident->generation)->where('kind', 'outage')
                    ->where(function ($query): void {
                        $query->whereIn('status', ['pending', 'retry'])
                            ->orWhere(fn ($processing) => $processing->where('status', 'processing')->where('possibly_delivered', false));
                    })->update([
                        'status' => 'cancelled', 'lease_token' => null, 'lease_expires_at' => null,
                        'last_error' => 'Monitoring recovered before delivery.', 'updated_at' => now(),
                    ]);
            }
            $this->enqueueRecovery(DB::table('monitoring_incidents')->find($incident->id));
        }, 3);
    }

    public function deliverable(object $alert): bool
    {
        $incident = DB::table('monitoring_incidents')->find($alert->incident_id);
        if ($incident === null || (int) $incident->generation !== (int) $alert->generation
            || ($alert->kind === 'outage' ? $incident->state !== 'outage' : ($alert->kind !== 'recovery' || $incident->state !== 'healthy'))) {
            return false;
        }
        $recipient = $this->recipient();

        return $recipient !== null && $recipient['user_id'] === (int) $alert->user_id
            && $recipient['chat_id'] === (string) $alert->chat_id;
    }

    /** Reserve only the approaching private-chat slot, not a long retry wait. */
    public function reservesChat(string $chatId): bool
    {
        $recipient = $this->recipient();
        if ($recipient === null || $recipient['chat_id'] !== $chatId) {
            return false;
        }

        return DB::table('monitoring_alerts as a')->join('monitoring_incidents as i', 'i.id', 'a.incident_id')
            ->where('a.user_id', $recipient['user_id'])->where('a.chat_id', $chatId)
            ->whereColumn('a.generation', 'i.generation')
            ->whereIn('a.status', ['pending', 'retry', 'processing'])
            ->where(function ($query): void {
                $query->where('a.status', 'processing')->orWhereNull('a.available_at')->orWhere('a.available_at', '<=', now()->addSecond());
            })
            ->where(function ($query): void {
                $query->where(fn ($outage) => $outage->where('a.kind', 'outage')->where('i.state', 'outage'))
                    ->orWhere(fn ($recovery) => $recovery->where('a.kind', 'recovery')->where('i.state', 'healthy'));
            })->exists();
    }

    /** Called after HTTP finishes or an expired potentially-sent lease cancels. */
    public function deliveryFinished(int $alertId): void
    {
        DB::transaction(function () use ($alertId): void {
            $alert = DB::table('monitoring_alerts')->find($alertId);
            if ($alert !== null && $alert->kind === 'outage') {
                $incident = DB::table('monitoring_incidents')->find($alert->incident_id);
                if ($incident !== null && $incident->state === 'healthy'
                    && (int) $incident->generation === (int) $alert->generation) {
                    $this->enqueueRecovery($incident);
                }
            }
        }, 3);
    }

    private function enqueueOutage(object $incident): void
    {
        $recipient = $this->recipient();
        if ($recipient === null) {
            return;
        }
        $monitor = $this->monitorName($incident->chain);
        $text = $monitor.' unavailable.'."\n"
            .'No provider completed the monitored check after '.$incident->consecutive_failures.' consecutive unavailable checks.'."\n"
            .'Category: '.$incident->error_category.".\n"
            .'Durable coverage is preserved. Incident: '.$incident->chain.'-'.$incident->id.'-'.$incident->generation.'.';
        $this->enqueue($incident, 'outage', $recipient, $text);
    }

    private function enqueueRecovery(object $incident): void
    {
        $outage = DB::table('monitoring_alerts')->where('incident_id', $incident->id)
            ->where('generation', $incident->generation)->where('kind', 'outage')->first();
        if ($outage === null || $outage->status === 'processing'
            || ($outage->status !== 'sent' && ! $outage->possibly_delivered)) {
            return;
        }
        $monitor = $this->monitorName($incident->chain);
        $recipient = ['user_id' => (int) $outage->user_id, 'chat_id' => (string) $outage->chat_id];
        $current = $this->recipient();
        $status = $current === $recipient ? 'pending' : 'cancelled';
        $this->enqueue($incident, 'recovery', $recipient,
            $monitor.' resumed. A provider check completed successfully.'."\n"
            .'Incident: '.$incident->chain.'-'.$incident->id.'-'.$incident->generation.'.', $status);
    }

    private function enqueue(object $incident, string $kind, array $recipient, string $text, string $status = 'pending'): void
    {
        DB::table('monitoring_alerts')->insertOrIgnore([
            'incident_id' => $incident->id, 'generation' => $incident->generation, 'kind' => $kind,
            'user_id' => $recipient['user_id'], 'chat_id' => $recipient['chat_id'], 'text' => $text,
            'status' => $status, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function recipient(): ?array
    {
        $setting = config('operations.alert_user_id', 1);
        $id = (is_int($setting) || is_string($setting))
            ? filter_var($setting, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $user = $id === false ? null : DB::table('users')->find($id);
        if ($user === null || ! $this->audience->allows((string) $user->telegram_user_id)
            || $this->access->activeGrant((int) $user->id) === null) {
            return null;
        }
        $allowed = DB::table('user_chat_destinations as p')->join('chat_destinations as d', 'd.id', 'p.destination_id')
            ->where('p.user_id', $user->id)->where('p.enabled', true)->whereNull('p.revoked_at')
            ->where('d.kind', 'private')->where('d.chat_id', $user->telegram_user_id)
            ->where('d.enabled', true)->whereNull('d.suspended_at')->exists();

        return $allowed ? ['user_id' => (int) $user->id, 'chat_id' => (string) $user->telegram_user_id] : null;
    }

    private function threshold(): int
    {
        $value = config('operations.alert_failure_threshold', 3);

        return max(3, min(1000, is_numeric($value) ? (int) $value : 3));
    }

    private function minimumDuration(): int
    {
        $value = config('operations.alert_min_outage_seconds', 30);

        return max(0, min(3600, is_numeric($value) ? (int) $value : 30));
    }

    private function checkInterval(): int
    {
        $value = config('operations.alert_check_interval_seconds', 15);

        return max(1, min(3600, is_numeric($value) ? (int) $value : 15));
    }

    private function checkChain(string $chain): void
    {
        if (! in_array($chain, ['ethereum', 'tron'], true)) {
            throw new InvalidArgumentException('A supported monitoring chain is required.');
        }
    }

    private function monitorName(string $chain): string
    {
        return $chain === 'ethereum' ? 'Ethereum monitoring' : 'TRON monitoring';
    }
}
