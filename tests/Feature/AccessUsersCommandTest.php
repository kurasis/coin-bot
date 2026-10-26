<?php

namespace Tests\Feature;

use App\Access\AccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessUsersCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_resolve_a_telegram_identity_without_exposing_activation_secrets(): void
    {
        $service = app(AccessService::class);
        $firstKey = $service->issue();
        $alice = $service->activate($firstKey->token, '100001');
        $secondKey = $service->issue();
        $bob = $service->activate($secondKey->token, '100002');
        $service->revokeGrant($bob->grantId);

        $this->artisan('access:users')->expectsTable(['User ID', 'Telegram user ID', 'Account state', 'Access'], [
            [$alice->userId, '100001', 'active', 'active'],
            [$bob->userId, '100002', 'active', 'inactive'],
        ])->doesntExpectOutputToContain($firstKey->token)->doesntExpectOutputToContain(hash('sha256', $firstKey->token))->assertSuccessful();
    }

    public function test_filtered_lookup_does_not_list_other_users(): void
    {
        $service = app(AccessService::class);
        $alice = $service->activate($service->issue()->token, '100001');
        $service->activate($service->issue()->token, '100002');

        $this->artisan('access:users', ['--telegram-id' => '100001'])
            ->expectsTable(['User ID', 'Telegram user ID', 'Account state', 'Access'], [[$alice->userId, '100001', 'active', 'active']])
            ->doesntExpectOutputToContain('100002')->assertSuccessful();
    }

    public function test_mutable_usernames_are_not_accepted_as_ownership_identifiers(): void
    {
        $this->artisan('access:users', ['--telegram-id' => '@alice'])->assertExitCode(2);
    }
}
