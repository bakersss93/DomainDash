<?php

namespace Tests\Feature\Monitoring;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Monitoring\AuditDescriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\Support\MonitoringHelpers;
use Tests\TestCase;

class ActivityAuditTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    public function test_mutating_request_gets_a_readable_description_and_old_new_diff(): void
    {
        $client = Client::create(['business_name' => 'Example Pty Ltd']);

        Route::middleware(['web', 'auth'])->put('/_audit/clients/{id}', function ($id) {
            Client::findOrFail($id)->update(['business_name' => 'Renamed Pty Ltd']);

            return response()->json(['ok' => true]);
        });

        $this->actingAs(User::factory()->create())->put('/_audit/clients/' . $client->id)->assertOk();

        $row = AuditLog::query()->latest('id')->first();
        $this->assertSame('Updated client Renamed Pty Ltd', $row->description);
        $this->assertSame('Example Pty Ltd', $row->old_values['business_name']);
        $this->assertSame('Renamed Pty Ltd', $row->new_values['business_name']);
        $this->assertStringNotContainsString('Manual HTTP action', $row->description);
    }

    public function test_unmapped_post_is_described_without_the_manual_http_wording_and_redacts_the_payload(): void
    {
        Route::middleware(['web', 'auth'])->post('/_audit-test', fn () => response()->json(['ok' => true]));
        $this->actingAs(User::factory()->create());

        $this->post('/_audit-test', ['sample' => 'value', 'password' => 'do-not-log', 'email' => 'jane@example.com'])->assertOk();

        $audit = AuditLog::query()->latest('id')->first();
        $this->assertStringStartsWith('Submitted', $audit->description);
        $this->assertSame('value', data_get($audit->new_values, 'payload.sample'));
        $this->assertSame('[REDACTED]', data_get($audit->new_values, 'payload.password'));
        $this->assertSame('j***@example.com', data_get($audit->new_values, 'payload.email'));
        $this->assertStringNotContainsString('do-not-log', json_encode($audit->new_values));
    }

    public function test_nested_secrets_are_redacted_recursively(): void
    {
        Route::middleware(['web', 'auth'])->post('/_audit-nested', fn () => response()->json(['ok' => true]));
        $this->actingAs(User::factory()->create());

        $this->post('/_audit-nested?token=querysecret', [
            'halo' => ['api_key' => 'halo-secret', 'tenant' => 'acme'],
            'vocus' => ['cert_password' => 'cert-secret', 'access_key' => 'ak-secret', 'AccessKey' => 'ak2-secret'],
            'deep' => ['a' => ['b' => ['Client_Secret' => 'deep-secret']]],
        ])->assertOk();

        $audit = AuditLog::query()->latest('id')->first();
        $json = json_encode([$audit->new_values, $audit->context]);
        foreach (['halo-secret', 'cert-secret', 'ak-secret', 'ak2-secret', 'deep-secret', 'querysecret'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertSame('acme', data_get($audit->new_values, 'payload.halo.tenant'));
    }

    public function test_request_already_audited_by_the_controller_is_not_duplicated(): void
    {
        Route::middleware(['web', 'auth'])->post('/_audit-explicit', function () {
            AuditLogger::logSystem('client.create', 'Created client Example Pty Ltd.', ['service' => 'clients']);

            return response()->json(['ok' => true]);
        });

        $this->actingAs(User::factory()->create())->post('/_audit-explicit')->assertOk();

        $this->assertSame(1, AuditLog::count());
        $this->assertSame('client.create', AuditLog::first()->action);
    }

    public function test_read_only_posts_and_validation_failures_are_skipped(): void
    {
        Route::middleware(['web', 'auth'])->post('/_audit/domains/availability', fn () => response()->json(['ok' => true]));
        Route::middleware(['web', 'auth'])->post('/_audit/invalid', fn () => response()->json(['message' => 'x'], 422));
        $this->actingAs(User::factory()->create());

        $this->post('/_audit/domains/availability')->assertOk();
        $this->post('/_audit/invalid')->assertStatus(422);

        $this->assertSame(0, AuditLog::count());
    }

    public function test_describer_maps_routes_to_sentences(): void
    {
        $cases = [
            ['PUT', 'admin/clients/{client}', ['client' => (new Client())->forceFill(['business_name' => 'Example Pty Ltd'])], 'Updated client Example Pty Ltd'],
            ['DELETE', 'admin/clients/{client}', ['client' => (new Client())->forceFill(['business_name' => 'Example Pty Ltd'])], 'Deleted client Example Pty Ltd'],
            ['POST', 'admin/services/internet/order', [], 'Ordered NBN service'],
            ['POST', 'admin/api-keys/{key}/regenerate', ['key' => (new Client())->forceFill(['name' => 'Automation'])], 'Regenerated API key Automation'],
        ];

        foreach ($cases as [$method, $uri, $params, $expected]) {
            $request = Request::create('/' . $uri, $method);
            $route = new RoutingRoute([$method], $uri, fn () => null);
            $route->bind($request);
            foreach ($params as $k => $v) {
                $route->setParameter($k, $v);
            }
            $request->setRouteResolver(fn () => $route);

            $this->assertSame($expected, AuditDescriber::describe($request)['description']);
        }
    }

    public function test_settings_change_names_the_groups_not_the_values(): void
    {
        $request = Request::create('/admin/settings', 'POST', ['halo' => ['api_key' => 'secret'], '_token' => 'x']);
        $route = new RoutingRoute(['POST'], 'admin/settings', fn () => null);
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);

        $this->assertSame('Changed HaloPSA settings', AuditDescriber::describe($request)['description']);
    }
}
