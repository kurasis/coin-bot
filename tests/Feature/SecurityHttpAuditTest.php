<?php

namespace Tests\Feature;

use App\Access\AccessService;
use App\Wallets\WalletService;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SecurityHttpAuditTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/coin-bot-http-audit-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        mkdir($this->directory.'/private', 0700);
        file_put_contents($this->directory.'/private/audit.txt', 'synthetic-private-file-body');
        config([
            'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'logging.default' => 'null',
            'filesystems.disks.local.root' => $this->directory.'/private',
        ]);
        Storage::forgetDisk('local');

        $access = app(AccessService::class);
        foreach (['71000001' => 'HTTP Alice private marker', '71000002' => 'HTTP Bob private marker'] as $telegramId => $label) {
            $user = $access->activate($access->issue()->token, (string) $telegramId)->userId;
            app(WalletService::class)->add($user, 'ethereum', '0x'.str_repeat('1', 40), $label, 100);
        }
    }

    protected function tearDown(): void
    {
        Storage::forgetDisk('local');
        foreach (glob($this->directory.'/private/*') as $file) {
            unlink($file);
        }
        foreach (glob($this->directory.'/sessions/*') as $file) {
            unlink($file);
        }
        if (is_dir($this->directory.'/sessions')) {
            rmdir($this->directory.'/sessions');
        }
        rmdir($this->directory.'/private');
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_only_the_two_documented_health_routes_are_registered(): void
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            $routes[$route->uri()] = $route->methods();
        }
        ksort($routes);
        $this->assertSame(['health' => ['GET', 'HEAD'], 'up' => ['GET', 'HEAD']], $routes);
        $this->assertFalse(config('filesystems.disks.local.serve'));
    }

    #[DataProvider('absentEndpoints')]
    public function test_unknown_api_admin_and_file_endpoints_cannot_read_or_mutate_private_state(string $method, string $path): void
    {
        $before = $this->snapshot();
        $response = $this->json($method, $path, [
            'user_id' => 2,
            'owner_id' => 2,
            'role' => 'admin',
            'tenant_id' => 2,
            'enabled' => true,
        ], [
            'Authorization' => 'Bearer forged-audit-credential',
            'X-User-ID' => '2',
            'X-Tenant-ID' => '2',
            'X-Forwarded-For' => '149.154.160.1',
            'X-Original-URL' => '/admin',
        ]);
        $response->assertNotFound();
        $response->assertDontSee('HTTP Alice private marker')->assertDontSee('HTTP Bob private marker');
        $response->assertDontSee('synthetic-private-file-body')->assertDontSee('token_hash');
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('synthetic-private-file-body', file_get_contents($this->directory.'/private/audit.txt'));
        $this->assertSame(['audit.txt'], array_map('basename', glob($this->directory.'/private/*')));
        Http::assertNothingSent();
    }

    public static function absentEndpoints(): array
    {
        return [
            'root' => ['GET', '/'],
            'user object and tenant query' => ['GET', '/api/v1/users/1?user_id=2&tenant_id=2'],
            'wallet list' => ['GET', '/api/wallets'],
            'wallet mutation' => ['PATCH', '/api/wallets/1'],
            'export' => ['GET', '/api/export?user_id=2'],
            'operator endpoint' => ['POST', '/admin/access-keys'],
            'graphql' => ['POST', '/graphql'],
            'websocket' => ['GET', '/ws'],
            'telegram webhook' => ['POST', '/webhooks/telegram'],
            'broadcast authorization' => ['POST', '/broadcasting/auth'],
            'cookie authentication' => ['GET', '/sanctum/csrf-cookie'],
            'debug dashboard' => ['GET', '/telescope'],
            'queue dashboard' => ['GET', '/horizon/api/jobs'],
            'environment file through kernel' => ['GET', '/.env'],
            'dependency file through kernel' => ['GET', '/composer.lock'],
            'private download' => ['GET', '/storage/audit.txt'],
            'private upload' => ['PUT', '/storage/audit.txt'],
        ];
    }

    public function test_unused_file_routes_stay_absent_even_for_cryptographically_valid_signed_urls(): void
    {
        $before = $this->snapshot();
        foreach ([false, true] as $upload) {
            $query = ['expires' => (string) now()->addMinute()->timestamp];
            if ($upload) {
                $query['upload'] = '1';
            }
            $uri = '/storage/audit.txt?'.http_build_query($query);
            $uri .= '&signature='.hash_hmac('sha256', $uri, config('app.key'));
            $this->assertTrue(Request::create($uri)->hasValidRelativeSignature());
            $this->call($upload ? 'PUT' : 'GET', $uri, server: ['HTTP_ACCEPT' => 'application/json'], content: 'attempted-overwrite')
                ->assertNotFound()->assertDontSee('synthetic-private-file-body');
        }
        $this->assertSame('synthetic-private-file-body', file_get_contents($this->directory.'/private/audit.txt'));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_health_is_read_only_with_boundary_query_types_and_uninterpreted_bodies(): void
    {
        $before = $this->snapshot();
        foreach ([
            ['application/json', '{"user_id":-1,"owner_id":2,"role":"admin","amount":null,"limit":9223372036854775808}'],
            ['application/json', str_repeat('[', 600).'0'.str_repeat(']', 600)],
            ['application/json', '{invalid-json'],
            ['application/xml', '<!DOCTYPE x [<!ENTITY audit SYSTEM "file://'.$this->directory.'/private/audit.txt">]><x>&audit;</x>'],
            ['application/x-www-form-urlencoded', 'user_id=1&user_id=2&owner_id[]=2&role=admin'],
            ['application/octet-stream', 'O:8:"stdClass":1:{s:4:"role";s:5:"admin";}'],
        ] as [$contentType, $body]) {
            $this->call('GET', '/health?user_id=1%20OR%201=1&tenant_id[]=2&limit=-1', server: [
                'CONTENT_TYPE' => $contentType,
                'HTTP_ACCEPT' => 'application/json',
            ], content: $body)->assertOk()->assertExactJson(['status' => 'ok']);
        }
        $this->getJson('/up')->assertOk()->assertExactJson(['status' => 'up']);
        $this->call('HEAD', '/health')->assertOk()->assertContent('');
        $this->call('HEAD', '/up')->assertOk()->assertContent('');
        $this->assertSame($before, $this->snapshot());
        Http::assertNothingSent();
    }

    public function test_health_routes_reject_write_methods_and_method_override_without_side_effects(): void
    {
        $before = $this->snapshot();
        foreach (['/health', '/up'] as $path) {
            foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'TRACE'] as $method) {
                $this->json($method, $path, ['user_id' => 2, 'role' => 'admin'])
                    ->assertStatus(405)->assertDontSee('HTTP Bob private marker');
            }
            $this->postJson($path, ['_method' => 'DELETE', 'role' => 'admin'], ['X-HTTP-Method-Override' => 'DELETE'])
                ->assertStatus(405);
        }
        $this->assertSame($before, $this->snapshot());
        Http::assertNothingSent();
    }

    public function test_public_health_probes_do_not_create_file_sessions_or_cookies(): void
    {
        mkdir($this->directory.'/sessions', 0700);
        config([
            'session.driver' => 'file',
            'session.files' => $this->directory.'/sessions',
            'session.lottery' => [0, 100],
        ]);
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        $before = $this->snapshot();
        $cookies = 0;
        for ($probe = 0; $probe < 3; $probe++) {
            $response = $this->getJson('/health')->assertOk()->assertExactJson(['status' => 'ok']);
            $cookies += count($response->headers->getCookies());
        }
        $this->assertCount(0, glob($this->directory.'/sessions/*'));
        $this->assertSame(0, $cookies);
        $this->assertSame($before, $this->snapshot());
        Http::assertNothingSent();
    }

    public function test_production_health_database_failure_does_not_disclose_error_or_private_state(): void
    {
        $before = $this->snapshot();
        $marker = 'synthetic-sensitive-database-marker';
        config([
            'database.connections.audit_missing' => [
                'driver' => 'sqlite',
                'database' => $this->directory.'/'.$marker.'/missing.sqlite',
                'prefix' => '',
            ],
            'database.default' => 'audit_missing',
        ]);
        try {
            $this->getJson('/health')->assertStatus(500)->assertExactJson(['message' => 'Server Error']);
            $this->get('/health')->assertStatus(500)->assertDontSee($marker)->assertDontSee('SQLSTATE')
                ->assertDontSee('HTTP Bob private marker')->assertDontSee('Stack trace');
        } finally {
            config(['database.default' => 'sqlite']);
            DB::purge('audit_missing');
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_production_liveness_failure_renders_only_generic_status(): void
    {
        $before = $this->snapshot();
        $marker = 'synthetic-sensitive-diagnostic-marker';
        Event::listen(DiagnosingHealth::class, function () use ($marker): void {
            throw new RuntimeException($marker);
        });
        $this->getJson('/up')->assertStatus(500)->assertExactJson(['status' => 'down']);
        $this->get('/up')->assertStatus(500)->assertDontSee($marker)->assertDontSee('Stack trace');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_foreign_origin_and_forwarding_headers_grant_no_personal_response_or_identity(): void
    {
        $before = $this->snapshot();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.8'])->getJson('/health', [
            'Origin' => 'https://outside-audit.invalid',
            'X-Forwarded-For' => '149.154.160.1',
            'X-Forwarded-Host' => 'admin-audit.invalid',
            'X-Forwarded-Proto' => 'https',
            'X-User-ID' => '2',
            'X-Tenant-ID' => '2',
        ])->assertOk()->assertExactJson(['status' => 'ok'])->assertHeaderMissing('Access-Control-Allow-Origin')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
        $this->assertSame('203.0.113.8', app('request')->ip());

        // Framework CORS preflight exists for the otherwise absent api/* namespace.
        $this->options('/api/wallets', [], [
            'Origin' => 'https://outside-audit.invalid',
            'Access-Control-Request-Method' => 'POST',
        ])->assertStatus(204)->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
        $this->postJson('/api/wallets', ['user_id' => 2])->assertNotFound();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_declared_oversized_request_is_rejected_without_allocating_a_large_body(): void
    {
        if ((int) ini_get('post_max_size') === 0) {
            $this->markTestSkipped('The runtime has disabled its POST-size guard.');
        }
        $before = $this->snapshot();
        $this->withServerVariables(['CONTENT_LENGTH' => (string) PHP_INT_MAX])->postJson('/health', ['small' => true])
            ->assertStatus(413)->assertDontSee('HTTP Bob private marker');
        $this->assertSame($before, $this->snapshot());
        Http::assertNothingSent();
    }

    private function snapshot(): string
    {
        $snapshot = [];
        foreach (DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table) {
            $rows = DB::table($table->name)->get()->map(fn (object $row): array => (array) $row)->all();
            usort($rows, fn (array $a, array $b): int => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));
            $snapshot[$table->name] = $rows;
        }

        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }
}
