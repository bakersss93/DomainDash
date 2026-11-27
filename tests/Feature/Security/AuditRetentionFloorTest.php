<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Monitoring\MonitoringPruner;
use App\Services\Monitoring\MonitoringSettings;
use App\Support\Monitoring\SecurityEvents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuditRetentionFloorTest extends TestCase
{
    use RefreshDatabase;

    private function row(string $action, int $daysOld): AuditLog
    {
        $log = AuditLog::create(['action' => $action, 'auditable_type' => 'System']);
        $log->forceFill(['created_at' => now()->subDays($daysOld)])->saveQuietly();

        return $log;
    }

    public function test_classification_covers_the_security_events(): void
    {
        foreach (['auth.login', 'auth.login_failed', 'impersonation.start', 'domain.auth-code-read', 'hosting.password-reveal',
                  'apikey.create', 'apikey.delete.failed', 'settings.update', 'saml.login', 'mfa.reset-cli'] as $a) {
            $this->assertTrue(SecurityEvents::isSecurityAction($a), $a);
        }
        foreach (['dns.create', 'sync.completed', 'client.update', 'http.get'] as $a) {
            $this->assertFalse(SecurityEvents::isSecurityAction($a), $a);
        }
    }

    public function test_pruning_with_a_short_retention_keeps_security_events_for_a_year(): void
    {
        $oldDns = $this->row('dns.create', 40);
        $secYoung = $this->row('auth.login', 200);
        $secOld = $this->row('auth.login', 400);
        $reveal = $this->row('domain.auth-code-read', 300);

        $deleted = AuditLogger::pruneOlderThanDays(30);

        $this->assertSame(2, $deleted); // dns.create (40d) and auth.login (400d)
        $this->assertNull(AuditLog::find($oldDns->id));
        $this->assertNull(AuditLog::find($secOld->id));
        $this->assertNotNull(AuditLog::find($secYoung->id));
        $this->assertNotNull(AuditLog::find($reveal->id));
    }

    public function test_a_security_retention_below_the_floor_is_raised_to_the_floor(): void
    {
        Setting::put('audit', ['retention_days' => 7, 'retention_days_security' => 10]);
        $this->assertSame(365, app(MonitoringSettings::class)->securityRetentionDays());

        $young = $this->row('auth.login', 100);
        app(MonitoringPruner::class)->prune();
        $this->assertNotNull(AuditLog::find($young->id));
    }

    public function test_retention_form_rejects_security_retention_below_the_floor(): void
    {
        Role::findOrCreate('Administrator', 'web');
        $admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
        $admin->assignRole('Administrator');

        $this->actingAs($admin)->post(route("admin.audit.retention"), ['retention_days' => 30, 'retention_days_security' => 30])
            ->assertSessionHasErrors('retention_days_security');

        $this->actingAs($admin)->post(route("admin.audit.retention"), ['retention_days' => 30, 'retention_days_security' => 400])
            ->assertSessionHasNoErrors();
        $this->assertSame(400, app(MonitoringSettings::class)->securityRetentionDays());
        $this->assertTrue(AuditLog::where('action', 'monitoring.settings')->exists());
    }

    public function test_syslog_forwarding_is_optional(): void
    {
        $spy = Log::spy();
        config(['monitoring.syslog' => false]);
        AuditLogger::logSystem('auth.login', 'x');
        $spy->shouldNotHaveReceived('channel');

        config(['monitoring.syslog' => true]);
        $channel = \Mockery::mock();
        $channel->shouldReceive('info')->once()->withArgs(fn ($m) => str_contains($m, '"action":"auth.login"'));
        Log::shouldReceive('channel')->with('audit_syslog')->once()->andReturn($channel);
        AuditLogger::logSystem('auth.login', 'x');
    }
}
