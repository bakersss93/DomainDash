<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Features;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Regression tests for the April 2026 review fixes (SSRF, impersonation, registration, audit/at-rest secrets). */
class AprilHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::findOrCreate('Administrator', 'web');
        $u = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
        $u->assignRole('Administrator');

        return $u;
    }

    public function test_settings_reject_private_base_urls(): void
    {
        $admin = $this->admin();
        foreach ([
            ['halo' => ['base_url' => 'https://169.254.169.254']],
            ['halo' => ['auth_server' => 'http://example.com']],
            ['itglue' => ['base_url' => 'https://127.0.0.1']],
            ['vocus' => ['wsdl_url' => 'https://10.0.0.5/wsdl']],
            ['vocus' => ['login_url' => 'https://192.168.0.1/login']],
        ] as $payload) {
            $this->actingAs($admin)->post(route('admin.settings.update'), $payload)->assertSessionHasErrors();
        }
        $this->assertNull(Setting::get('halo'));
        $this->assertNull(Setting::get('itglue'));
    }

    public function test_settings_accept_public_https_urls(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.settings.update'), ['itglue' => ['base_url' => 'https://8.8.8.8']])
            ->assertSessionHasNoErrors();
        $this->assertSame('https://8.8.8.8', Setting::get('itglue')['base_url']);
    }

    public function test_impersonation_blocks_admins_inactive_and_self_but_allows_normal_users(): void
    {
        $admin = $this->admin();
        $other = $this->admin();
        $inactive = User::factory()->create(['is_active' => false]);
        $normal = User::factory()->create(['is_active' => true]);

        $this->actingAs($admin)->post(route('admin.users.impersonate', $other))->assertSessionHasErrors('user');
        $this->assertNull(session('impersonate_as'));
        $this->actingAs($admin)->post(route('admin.users.impersonate', $inactive))->assertSessionHasErrors('user');
        $this->assertNull(session('impersonate_as'));
        $this->actingAs($admin)->post(route('admin.users.impersonate', $admin))->assertSessionHasErrors('user');
        $this->actingAs($admin)->post(route('admin.users.impersonate', $normal))->assertRedirect(route('dashboard'));
        $this->assertSame($normal->id, session('impersonate_as'));
    }

    public function test_public_registration_is_disabled_by_default(): void
    {
        $this->assertFalse(Features::enabled(Features::registration()));
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['name' => 'X', 'email' => 'x@example.com', 'password' => 'Str0ng-Passw0rd!x', 'password_confirmation' => 'Str0ng-Passw0rd!x'])
            ->assertStatus(404);
        $this->assertDatabaseMissing('users', ['email' => 'x@example.com']);
    }

    public function test_sensitive_settings_encrypted_at_rest_and_not_in_audit_log(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.settings.update'), [
            'halo' => ['api_key' => 'halo-secret-XYZ', 'client_id' => 'cid'],
            'vocus' => ['cert_password' => 'cert-pass-ABC', 'access_key' => 'vocus-access-123'],
            'backup' => ['password' => 'backup-pass-QQQ'],
        ])->assertSessionHasNoErrors();

        $raw = DB::table('settings')->pluck('value', 'key');
        foreach (['halo', 'vocus', 'backup'] as $k) {
            $this->assertStringStartsWith('enc:v1:', $raw[$k]);
        }
        $this->assertSame('halo-secret-XYZ', Setting::get('halo')['api_key']);

        $logs = json_encode(AuditLog::all()->toArray());
        foreach (['halo-secret-XYZ', 'cert-pass-ABC', 'vocus-access-123', 'backup-pass-QQQ'] as $secret) {
            $this->assertStringNotContainsString($secret, $logs);
        }
    }

    public function test_generic_request_audit_payload_redacts_secrets(): void
    {
        $admin = $this->admin();
        // An unhandled POST to a route without a ChangeTracker diff falls back to the sanitised payload.
        $this->actingAs($admin)->post(route('admin.settings.update'), [
            'notifications' => ['disk_threshold_percent' => 95],
        ]);
        $mw = new \ReflectionMethod(\App\Http\Middleware\AuditRequestActions::class, 'sanitizedPayload');
        $mw->setAccessible(true);
        $req = \Illuminate\Http\Request::create('/x', 'POST', ['password' => 'p1', 'api_key' => 'k1', 'x' => ['client_secret' => 's1', 'cert_passphrase' => 'c1', 'token' => 't1'], 'name' => 'ok']);
        $out = $mw->invoke(new \App\Http\Middleware\AuditRequestActions(), $req);
        $json = json_encode($out);
        foreach (['p1', 'k1', 's1', 'c1', 't1'] as $s) {
            $this->assertStringNotContainsString('"' . $s . '"', $json);
        }
        $this->assertSame('ok', $out['name']);
    }
}
