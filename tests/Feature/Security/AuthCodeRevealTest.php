<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\User;
use App\Services\Synergy\SynergyWholesaleClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthCodeRevealTest extends TestCase
{
    use RefreshDatabase;

    private function adminAndDomain(): array
    {
        Role::findOrCreate('Administrator', 'web');
        $perm = Permission::findOrCreate('domains.transfer', 'web');
        $admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
        $admin->assignRole('Administrator');
        $admin->givePermissionTo($perm);
        $domain = Domain::create(['name' => 'example.com.au']);

        return [$admin, $domain];
    }

    public function test_reveal_requires_a_recent_password_confirmation(): void
    {
        [$admin, $domain] = $this->adminAndDomain();
        $this->mock(SynergyWholesaleClient::class, fn ($m) => $m->shouldNotReceive('getDomainPassword'));

        $this->actingAs($admin)->getJson(route('admin.domains.auth-code', $domain))
            ->assertStatus(423)
            ->assertJsonPath('ok', false)
            ->assertJsonStructure(['confirm_url']);
        $this->assertFalse(AuditLog::where('action', 'domain.auth-code-read')->exists());
    }

    public function test_confirmed_reveal_is_audit_logged(): void
    {
        [$admin, $domain] = $this->adminAndDomain();
        $this->mock(SynergyWholesaleClient::class, fn ($m) => $m->shouldReceive('getDomainPassword')->once()->andReturn('EPP-SECRET-1'));

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->getJson(route('admin.domains.auth-code', $domain))
            ->assertOk()->assertJsonPath('code', 'EPP-SECRET-1');

        $log = AuditLog::where('action', 'domain.auth-code-read')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('EPP-SECRET-1', json_encode($log->toArray()));
    }

    public function test_registrar_errors_do_not_leak_details(): void
    {
        [$admin, $domain] = $this->adminAndDomain();
        $this->mock(SynergyWholesaleClient::class, fn ($m) => $m->shouldReceive('getDomainPassword')->andThrow(new \RuntimeException('SOAP fault: apiKey=ABC123 at https://internal')));

        $res = $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->getJson(route('admin.domains.auth-code', $domain))->assertStatus(500);
        $this->assertSame('Unable to fetch auth code.', $res->json('message'));
        $this->assertStringNotContainsString('ABC123', $res->getContent());
    }
}
