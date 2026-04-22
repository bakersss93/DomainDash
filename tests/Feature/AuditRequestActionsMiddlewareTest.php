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
        $this->assertArrayNotHasKey('password', data_get($audit->new_values, 'payload', []));
    }
}
