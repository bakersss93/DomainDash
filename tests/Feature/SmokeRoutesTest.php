<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Domain;
use App\Models\HostingService;
use App\Models\InternetService;
use App\Models\SslCertificate;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Render check: GET every web route as an Administrator (RolePermissionSeeder permissions) and as a Customer and
 * fail on any 5xx, which is how Blade compile errors and runtime errors in views show up. Administrators must also
 * never get a 403 on an admin page. Outbound HTTP is faked, so remote-backed pages render their error states.
 */
class SmokeRoutesTest extends TestCase
{
    use RefreshDatabase;

    /** URIs that are not pages of this app (framework / package endpoints, signed links, JSON APIs). */
    private const SKIP = '#^(api/|oauth/|livewire/|sanctum/|up$|storage/|email/verify|reset-password|team-invitations|two-factor-challenge|user/(two-factor|confirm|confirmed)|forgot-password|login|logout|admin/sync/|admin/domains/[^/]+/auth-code)#';

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(fn (HttpRequest $r) => Http::response([], 200));
        $this->seed(RolePermissionSeeder::class);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['two_factor_secret' => encrypt('SECRET'), 'two_factor_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    /** @return array<string,mixed> */
    private function records(): array
    {
        $client = Client::create(['business_name' => 'Smoke Pty Ltd']);

        return [
            'client' => $client,
            'domain' => Domain::create(['name' => 'smoke.example.com', 'client_id' => $client->id, 'status' => 'active']),
            'hosting' => HostingService::forceCreate(['client_id' => $client->id, 'domain_name' => 'smoke.example.com', 'service_status' => 'ACTIVE']),
            'internet' => InternetService::forceCreate(['vocus_service_id' => 'DD-SMOKE', 'client_id' => $client->id, 'service_status' => 'ACTIVE', 'service_type' => 'FTTC']),
            'ssl' => SslCertificate::forceCreate(['client_id' => $client->id, 'common_name' => 'smoke.example.com']),
            'user' => $this->user('Customer'),
        ];
    }

    /** Concrete URL for a route, or null when a parameter has no sensible fixture. */
    private function urlFor(LaravelRoute $route, array $r): ?string
    {
        $uri = $route->uri();
        foreach ($route->parameterNames() as $name) {
            $value = match (true) {
                $name === 'domain' => $r['domain']->getKey(),
                $name === 'client' => $r['client']->getKey(),
                $name === 'ssl' => $r['ssl']->getKey(),
                $name === 'user' => $r['user']->getKey(),
                $name === 'service' => str_contains($uri, 'internet') ? $r['internet']->getKey() : $r['hosting']->getKey(),
                $name === 'area' => 'general',
                $name === 'mapping' => 'asset-types',
                $name === 'source' => 'ticket-types',
                $name === 'tab' => 'activity',
                $name === 'id' => 1,
                default => null,
            };
            if ($value === null) {
                return null;
            }
            $uri = preg_replace('#\{' . $name . '\??\}#', (string) $value, $uri);
        }

        return '/' . ltrim($uri, '/');
    }

    /** @return array<int,array{0:string,1:string}> [uri, url] */
    private function pages(array $r): array
    {
        $pages = [];
        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true) || preg_match(self::SKIP, $route->uri())) {
                continue;
            }
            if (($url = $this->urlFor($route, $r)) !== null) {
                $pages[$route->uri()] = $url;
            }
        }

        return $pages;
    }

    public function test_every_page_renders_for_an_administrator(): void
    {
        $r = $this->records();
        $admin = $this->user('Administrator');
        $pages = $this->pages($r);
        $this->assertGreaterThan(40, count($pages), 'the route sweep found too few pages');

        $failures = [];
        foreach ($pages as $uri => $url) {
            $response = $this->actingAs($admin)->get($url);
            $status = $response->getStatusCode();
            if ($status >= 500 || $status === 403) {
                $failures[] = "{$status} {$url} (" . ($response->exception?->getMessage() ?? '') . ')';
            }
        }

        $this->assertSame([], $failures, "Pages that failed as Administrator:\n" . implode("\n", $failures));
    }

    public function test_no_page_errors_for_a_customer(): void
    {
        $r = $this->records();
        $customer = $r['user'];
        $failures = [];
        foreach ($this->pages($r) as $url) {
            $status = $this->actingAs($customer)->get($url)->getStatusCode();
            if ($status >= 500) {
                $failures[] = "{$status} {$url}";
            }
        }

        $this->assertSame([], $failures, "Pages that errored as Customer:\n" . implode("\n", $failures));
    }
}
