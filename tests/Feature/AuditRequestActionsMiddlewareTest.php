<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuditRequestActionsMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_requests_are_audited(): void
    {
        Route::middleware(['web', 'auth'])->post('/_audit-test', fn () => response()->json(['ok' => true]));

        $user = User::factory()->create();
        $this->actingAs($user);

        $this->post('/_audit-test', [
            'sample' => 'value',
            'password' => 'do-not-log',
        ])->assertOk();

        $audit = AuditLog::query()
            ->where('action', 'http.post')
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame('Manual HTTP action POST /_audit-test', $audit->description);
        $this->assertSame('value', data_get($audit->new_values, 'payload.sample'));
        $this->assertSame('[REDACTED]', data_get($audit->new_values, 'payload.password'));
        $this->assertStringNotContainsString('do-not-log', json_encode($audit->new_values));
    }

    public function test_nested_secrets_are_redacted_recursively(): void
    {
        Route::middleware(['web', 'auth'])->post('/_audit-nested', fn () => response()->json(['ok' => true]));

        $this->actingAs(User::factory()->create());

        $this->post('/_audit-nested?token=querysecret', [
            'halo' => ['api_key' => 'halo-secret', 'tenant' => 'acme'],
            'smtp' => ['password' => 'smtp-secret'],
            'vocus' => ['cert_password' => 'cert-secret', 'access_key' => 'ak-secret'],
            'deep' => ['a' => ['b' => ['Client_Secret' => 'deep-secret']]],
        ])->assertOk();

        $audit = AuditLog::query()->where('action', 'http.post')->latest('id')->first();
        $json = json_encode($audit->new_values);

        foreach (['halo-secret', 'smtp-secret', 'cert-secret', 'ak-secret', 'deep-secret', 'querysecret'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertSame('acme', data_get($audit->new_values, 'payload.halo.tenant'));
        $this->assertSame('[REDACTED]', data_get($audit->new_values, 'payload.halo.api_key'));
    }
}
