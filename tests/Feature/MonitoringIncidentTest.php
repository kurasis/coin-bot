<?php

namespace Tests\Feature;

use App\Access\AccessService;
use App\Operations\MaintenanceGuard;
use App\Operations\MonitoringIncidentService;
use App\Telegram\DispatchRateGate;
use App\Telegram\MonitoringAlertWorker;
use App\Telegram\QueueLeases;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MonitoringIncidentTest extends TestCase
{
    use RefreshDatabase;

    private int $alice;

    private int $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config([
            'operations.alert_user_id' => 1,
            'operations.alert_failure_threshold' => 3,
            'operations.alert_min_outage_seconds' => 30,
            'operations.alert_check_interval_seconds' => 15,
            'operations.state_directory' => sys_get_temp_dir().'/coin-bot-monitoring-test-'.bin2hex(random_bytes(8)),
            'telegram.allowed_user_ids' => '',
            'telegram.token' => 'synthetic-monitoring-token',
            'ethereum.early_enabled' => false,
        ]);
        $this->alice = $this->user('730000001');
        $this->bob = $this->user('730000002');
        $this->assertSame(1, $this->alice);
    }

    protected function tearDown(): void
    {
        $directory = config('operations.state_directory');
        if (is_file($directory.'/paused')) {
            unlink($directory.'/paused');
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
        parent::tearDown();
    }

    public function test_two_failures_and_checked_success_do_not_notify_or_create_an_incident(): void
    {
        $service = app(MonitoringIncidentService::class);
        $service->failed('ethereum');
        $this->travel(15)->seconds();
        $service->failed('ethereum', 'rate_limited');
        $this->assertDatabaseCount('monitoring_alerts', 0);
        $service->succeeded('ethereum');
        $this->assertDatabaseHas('monitoring_incidents', ['chain' => 'ethereum', 'state' => 'healthy', 'consecutive_failures' => 0, 'generation' => 0]);
        $this->travel(15)->seconds();
        $service->failed('ethereum');
        $this->assertDatabaseHas('monitoring_incidents', ['chain' => 'ethereum', 'consecutive_failures' => 1]);
        $this->assertDatabaseCount('monitoring_alerts', 0);
        Http::assertNothingSent();
    }

    public function test_three_sustained_failed_cycles_open_one_durable_alert_across_service_restarts(): void
    {
        app(MonitoringIncidentService::class)->failed('ethereum');
        $this->travel(15)->seconds();
        app(MonitoringIncidentService::class)->failed('ethereum');
        $this->assertDatabaseCount('monitoring_alerts', 0);
        $this->travel(15)->seconds();
        app(MonitoringIncidentService::class)->failed('ethereum', 'rate_limited');
        $this->assertDatabaseHas('monitoring_incidents', ['chain' => 'ethereum', 'state' => 'outage', 'consecutive_failures' => 3, 'generation' => 1]);
        $this->assertDatabaseHas('monitoring_alerts', ['user_id' => $this->alice, 'chat_id' => '730000001', 'kind' => 'outage', 'status' => 'pending']);
        for ($i = 0; $i < 4; $i++) {
            $this->travel(15)->seconds();
            app(MonitoringIncidentService::class)->failed('ethereum');
        }
        $this->assertDatabaseCount('monitoring_alerts', 1);
        $this->assertDatabaseHas('monitoring_incidents', ['chain' => 'ethereum', 'consecutive_failures' => 7]);
        $this->assertDatabaseCount('telegram_replies', 0);
        Http::assertNothingSent();
    }

    public function test_three_instant_failures_wait_for_the_minimum_outage_duration(): void
    {
        $service = app(MonitoringIncidentService::class);
        for ($i = 0; $i < 3; $i++) {
            $service->failed('tron');
        }
        $this->assertDatabaseCount('monitoring_alerts', 0);
        $this->assertDatabaseHas('monitoring_incidents', ['chain' => 'tron', 'consecutive_failures' => 1]);
        $this->travel(15)->seconds();
        $service->failed('tron');
        $this->assertDatabaseCount('monitoring_alerts', 0);
        $this->travel(15)->seconds();
        $service->failed('tron');
        $this->assertDatabaseCount('monitoring_alerts', 1);
    }

    public function test_user_id_is_internal_and_chain_failure_streaks_are_independent(): void
    {
        config(['operations.alert_user_id' => '730000001']);
        $this->outage('ethereum');
        $this->assertDatabaseCount('monitoring_alerts', 0);
        config(['operations.alert_user_id' => $this->bob]);
        app(MonitoringIncidentService::class)->failed('ethereum');
        $this->assertDatabaseHas('monitoring_alerts', ['user_id' => $this->bob, 'chat_id' => '730000002']);
        app(MonitoringIncidentService::class)->failed('tron');
        app(MonitoringIncidentService::class)->succeeded('tron');
        $this->assertDatabaseHas('monitoring_incidents', ['chain' => 'ethereum', 'state' => 'outage']);
        $this->assertDatabaseHas('monitoring_incidents', ['chain' => 'tron', 'state' => 'healthy', 'consecutive_failures' => 0]);
    }

    public function test_recovery_before_outage_delivery_cancels_it_without_orphan_recovery(): void
    {
        $this->outage();
        app(MonitoringIncidentService::class)->succeeded('ethereum');
        $this->assertDatabaseCount('monitoring_alerts', 1);
        $this->assertDatabaseHas('monitoring_alerts', ['kind' => 'outage', 'status' => 'cancelled']);
        $this->assertFalse(app(MonitoringAlertWorker::class)->runOnce());
        Http::assertNothingSent();
    }

    public function test_delivered_outage_and_recovery_notify_only_the_authorized_configured_user_once(): void
    {
        $this->fakeSuccess();
        $this->outage();
        $this->assertTrue(app(MonitoringAlertWorker::class)->runOnce());
        $this->assertDatabaseHas('monitoring_alerts', ['kind' => 'outage', 'status' => 'sent']);
        app(MonitoringIncidentService::class)->succeeded('ethereum');
        app(MonitoringIncidentService::class)->succeeded('ethereum');
        $this->assertDatabaseCount('monitoring_alerts', 2);
        $this->travel(2)->seconds();
        $this->assertTrue(app(MonitoringAlertWorker::class)->runOnce());
        $this->assertFalse(app(MonitoringAlertWorker::class)->runOnce());
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => (string) $request['chat_id'] === '730000001'
            && str_contains($request['text'], 'Ethereum monitoring unavailable.'));
        Http::assertSent(fn (Request $request): bool => (string) $request['chat_id'] === '730000001'
            && str_contains($request['text'], 'monitoring resumed'));
        Http::assertNotSent(fn (Request $request): bool => (string) $request['chat_id'] === '730000002');
    }

    public function test_a_new_outage_generation_can_notify_again_but_stale_recovery_cannot_send_during_it(): void
    {
        $this->fakeSuccess();
        $this->outage();
        app(MonitoringAlertWorker::class)->runOnce();
        app(MonitoringIncidentService::class)->succeeded('ethereum');
        $this->outage();
        $this->assertDatabaseHas('monitoring_incidents', ['generation' => 2, 'state' => 'outage']);
        app(MonitoringAlertWorker::class)->runOnce(); // stale recovery cancels
        $this->assertDatabaseHas('monitoring_alerts', ['kind' => 'recovery', 'generation' => 1, 'status' => 'cancelled']);
        app(MonitoringAlertWorker::class)->runOnce();
        $this->assertDatabaseHas('monitoring_alerts', ['kind' => 'outage', 'generation' => 2, 'status' => 'sent']);
        Http::assertSentCount(2);
    }

    #[DataProvider('denialStates')]
    public function test_outage_delivery_revalidates_private_access_and_current_configuration(string $state): void
    {
        $this->outage();
        $this->denyAlice($state);
        $this->assertTrue(app(MonitoringAlertWorker::class)->runOnce());
        $this->assertDatabaseHas('monitoring_alerts', ['status' => 'cancelled']);
        Http::assertNothingSent();
    }

    public static function denialStates(): array
    {
        return array_map(fn (string $state): array => [$state], ['revoked', 'expired', 'disabled', 'allowlist', 'paused_destination', 'permission', 'changed_target', 'forged_chat']);
    }

    #[DataProvider('invalidRecipients')]
    public function test_invalid_recipient_settings_never_guess_a_chat_or_send(mixed $setting): void
    {
        config(['operations.alert_user_id' => $setting]);
        $this->outage();
        $this->assertDatabaseCount('monitoring_alerts', 0);
        Http::assertNothingSent();
    }

    public static function invalidRecipients(): array
    {
        return [[true], [false], [null], [''], ['user1'], ['1,2'], [-1], [0], [['1']]];
    }

    public function test_maintenance_does_not_claim_or_send_alerts(): void
    {
        $this->outage();
        app(MaintenanceGuard::class)->pause();
        $this->assertFalse(app(MonitoringAlertWorker::class)->runOnce());
        $this->assertDatabaseHas('monitoring_alerts', ['status' => 'pending', 'attempts' => 0]);
        Http::assertNothingSent();
    }

    public function test_recovery_while_http_is_in_flight_queues_one_recovery_after_the_outage_is_recorded_sent(): void
    {
        $this->outage();
        Http::fake(function (Request $request) {
            app(MonitoringIncidentService::class)->succeeded('ethereum');
            $this->assertDatabaseHas('monitoring_alerts', ['kind' => 'outage', 'status' => 'processing', 'possibly_delivered' => true]);
            $this->assertDatabaseCount('monitoring_alerts', 1);

            return Http::response(['ok' => true, 'result' => ['message_id' => 1, 'chat' => ['id' => $request['chat_id']]]]);
        });
        app(MonitoringAlertWorker::class)->runOnce();
        $this->assertDatabaseHas('monitoring_alerts', ['kind' => 'outage', 'status' => 'sent']);
        $this->assertDatabaseHas('monitoring_alerts', ['kind' => 'recovery', 'status' => 'pending']);
        $this->assertDatabaseCount('monitoring_alerts', 2);
        Http::assertSentCount(1);
    }

    public function test_known_telegram_rejection_does_not_create_a_false_recovery_message(): void
    {
        $this->outage();
        Http::fake(fn () => Http::response(['ok' => false, 'error_code' => 403], 403));
        app(MonitoringAlertWorker::class)->runOnce();
        $this->assertDatabaseHas('monitoring_alerts', ['kind' => 'outage', 'status' => 'failed', 'possibly_delivered' => false]);
        app(MonitoringIncidentService::class)->succeeded('ethereum');
        $this->assertDatabaseCount('monitoring_alerts', 1);
    }

    public function test_ambiguous_send_followed_by_recovery_cancels_the_old_alert_and_queues_one_recovery(): void
    {
        $this->outage();
        Http::fake(fn () => throw new ConnectionException('A synthetic credential-bearing URL must never survive.'));
        app(MonitoringAlertWorker::class)->runOnce();
        $this->assertDatabaseHas('monitoring_alerts', ['kind' => 'outage', 'status' => 'retry', 'possibly_delivered' => true]);
        app(MonitoringIncidentService::class)->succeeded('ethereum');
        $this->assertDatabaseHas('monitoring_alerts', ['kind' => 'outage', 'status' => 'cancelled']);
        $this->assertDatabaseHas('monitoring_alerts', ['kind' => 'recovery', 'status' => 'pending']);
        $this->assertStringNotContainsString('credential-bearing', DB::table('monitoring_alerts')->value('last_error') ?? '');
    }

    public function test_a_crashed_potentially_sent_lease_can_finish_recovery_without_resending_stale_outage(): void
    {
        $this->outage();
        $lease = app(QueueLeases::class)->claim('monitoring_alerts');
        app(QueueLeases::class)->beginAttempt('monitoring_alerts', $lease);
        DB::table('monitoring_alerts')->where('id', $lease->id)->update(['possibly_delivered' => true]);
        app(MonitoringIncidentService::class)->succeeded('ethereum');
        $this->assertDatabaseCount('monitoring_alerts', 1);
        $this->travel(120)->seconds();
        app(MonitoringAlertWorker::class)->runOnce();
        $this->assertDatabaseHas('monitoring_alerts', ['kind' => 'outage', 'status' => 'cancelled']);
        $this->assertDatabaseHas('monitoring_alerts', ['kind' => 'recovery', 'status' => 'pending']);
        Http::assertNothingSent();
    }

    public function test_error_details_and_unsupported_thresholds_cannot_bypass_the_three_failure_guard(): void
    {
        config(['operations.alert_failure_threshold' => 1, 'operations.alert_min_outage_seconds' => 0]);
        $service = app(MonitoringIncidentService::class);
        $service->failed('ethereum', 'https://provider.invalid/private-api-key');
        $this->travel(15)->seconds();
        $service->failed('ethereum', 'private wallet and token');
        $this->assertDatabaseCount('monitoring_alerts', 0);
        $this->travel(15)->seconds();
        $service->failed('ethereum', 'https://provider.invalid/private-api-key');
        $row = DB::table('monitoring_alerts')->first();
        $this->assertStringNotContainsString('private-api-key', $row->text);
        $this->assertStringNotContainsString('https://', $row->text);
        $this->assertDatabaseHas('monitoring_incidents', ['error_category' => 'api_unavailable']);
    }

    public function test_ethereum_incident_describes_shared_availability_without_claiming_a_particular_pipeline_failed(): void
    {
        config(['ethereum.early_enabled' => true]);
        $this->outage();
        $text = DB::table('monitoring_alerts')->value('text');
        $this->assertStringContainsString('Ethereum monitoring unavailable.', $text);
        $this->assertStringNotContainsString('fast', $text);
        $this->assertStringNotContainsString('finalized', $text);
    }

    public function test_long_retry_after_windows_are_sampled_without_counting_rapid_restarts_or_workers_twice(): void
    {
        app(MonitoringIncidentService::class)->failed('ethereum', 'rate_limited');
        app(MonitoringIncidentService::class)->blocked('ethereum');
        $this->travel(5)->seconds();
        app(MonitoringIncidentService::class)->blocked('ethereum');
        $this->travel(5)->seconds();
        app(MonitoringIncidentService::class)->failed('ethereum');
        $this->assertDatabaseHas('monitoring_incidents', ['chain' => 'ethereum', 'consecutive_failures' => 1]);
        $this->assertDatabaseCount('monitoring_alerts', 0);
        $this->travel(5)->seconds();
        app(MonitoringIncidentService::class)->blocked('ethereum');
        $this->assertDatabaseHas('monitoring_incidents', ['chain' => 'ethereum', 'consecutive_failures' => 2]);
        $this->travel(15)->seconds();
        app(MonitoringIncidentService::class)->blocked('ethereum');
        $this->assertDatabaseHas('monitoring_incidents', ['chain' => 'ethereum', 'consecutive_failures' => 3, 'state' => 'outage']);
        $this->assertDatabaseCount('monitoring_alerts', 1);
        Http::assertNothingSent();
    }

    public function test_alert_priority_reserves_only_its_own_chat_and_does_not_charge_a_rate_slot(): void
    {
        $this->outage();
        $this->assertSame(1000, app(DispatchRateGate::class)->reserve('730000001'));
        $this->assertDatabaseCount('telegram_dispatch_state', 0);
        $this->assertSame(0, app(DispatchRateGate::class)->reserve('730000002'));
        $this->travel(1)->seconds();
        $this->fakeSuccess();
        app(MonitoringAlertWorker::class)->runOnce();
        $this->assertDatabaseHas('monitoring_alerts', ['status' => 'sent']);
        $this->travel(1)->seconds();
        $this->assertSame(0, app(DispatchRateGate::class)->reserve('730000001'));
        Http::assertSentCount(1);
    }

    public function test_priority_does_not_block_normal_messages_during_a_long_alert_retry_backoff(): void
    {
        $this->outage();
        DB::table('monitoring_alerts')->update(['status' => 'retry', 'available_at' => now()->addSeconds(300)]);
        $this->assertSame(0, app(DispatchRateGate::class)->reserve('730000001'));
        $this->travel(1)->seconds();
        $this->assertSame(0, app(DispatchRateGate::class)->reserve('730000002'));
        $this->travel(298)->seconds();
        $this->assertSame(1000, app(DispatchRateGate::class)->reserve('730000001'));
        $this->assertFalse(app(MonitoringAlertWorker::class)->runOnce());
        $this->travel(1)->seconds();
        $this->fakeSuccess();
        $this->assertTrue(app(MonitoringAlertWorker::class)->runOnce());
        $this->assertDatabaseHas('monitoring_alerts', ['status' => 'sent']);
        Http::assertSentCount(1);
    }

    public function test_a_definite_rejection_after_recovery_during_http_does_not_send_an_orphan_recovery(): void
    {
        $this->outage();
        Http::fake(function () {
            app(MonitoringIncidentService::class)->succeeded('ethereum');

            return Http::response(['ok' => false, 'error_code' => 403], 403);
        });
        app(MonitoringAlertWorker::class)->runOnce();
        $this->assertDatabaseHas('monitoring_alerts', ['kind' => 'outage', 'status' => 'cancelled', 'possibly_delivered' => false]);
        $this->assertDatabaseCount('monitoring_alerts', 1);
    }

    public function test_circuit_waits_preserve_known_failure_category_without_inventing_a_rate_limit(): void
    {
        $service = app(MonitoringIncidentService::class);
        $service->failed('ethereum', 'invalid_response');
        $this->travel(15)->seconds();
        $service->blocked('ethereum');
        $this->assertDatabaseHas('monitoring_incidents', ['chain' => 'ethereum', 'error_category' => 'invalid_response', 'consecutive_failures' => 2]);
        $service->blocked('tron');
        $this->assertDatabaseHas('monitoring_incidents', ['chain' => 'tron', 'error_category' => 'api_unavailable', 'consecutive_failures' => 1]);
    }

    private function outage(string $chain = 'ethereum'): void
    {
        app(MonitoringIncidentService::class)->failed($chain);
        $this->travel(15)->seconds();
        app(MonitoringIncidentService::class)->failed($chain);
        $this->travel(15)->seconds();
        app(MonitoringIncidentService::class)->failed($chain);
    }

    private function user(string $telegramId): int
    {
        $access = app(AccessService::class);
        $id = $access->activate($access->issue()->token, $telegramId)->userId;
        $destination = DB::table('chat_destinations')->insertGetId([
            'chat_id' => $telegramId, 'kind' => 'private', 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('user_chat_destinations')->insert([
            'user_id' => $id, 'destination_id' => $destination, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function fakeSuccess(): void
    {
        Http::fake(fn (Request $request) => Http::response([
            'ok' => true, 'result' => ['message_id' => 1, 'chat' => ['id' => $request['chat_id']]],
        ]));
    }

    private function denyAlice(string $state): void
    {
        match ($state) {
            'revoked' => app(AccessService::class)->revokeGrant((int) DB::table('access_grants')->where('user_id', $this->alice)->value('id')),
            'expired' => DB::table('access_grants')->where('user_id', $this->alice)->update(['expires_at' => now()->subSecond()]),
            'disabled' => DB::table('users')->where('id', $this->alice)->update(['state' => 'disabled']),
            'allowlist' => config(['telegram.allowed_user_ids' => '730000002']),
            'paused_destination' => DB::table('chat_destinations')->where('chat_id', '730000001')->update(['suspended_at' => now()]),
            'permission' => DB::table('user_chat_destinations')->where('user_id', $this->alice)->update(['enabled' => false]),
            'changed_target' => config(['operations.alert_user_id' => $this->bob]),
            'forged_chat' => DB::table('monitoring_alerts')->update(['chat_id' => '730000002']),
        };
    }
}
