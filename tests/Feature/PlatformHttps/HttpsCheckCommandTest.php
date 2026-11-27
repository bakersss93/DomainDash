<?php

namespace Tests\Feature\PlatformHttps;

use App\Models\EmailTemplate;
use App\Models\PlatformCertificate;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Https\PlatformHttps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HttpsCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    private function cert(int $days, string $fp = 'FP1'): PlatformCertificate
    {
        PlatformCertificate::query()->delete();

        return PlatformCertificate::create(['hostname' => 'dash.example.com', 'source' => 'letsencrypt', 'fingerprint_sha256' => $fp,
            'not_before' => now()->subDay(), 'not_after' => now()->addDays($days)->addHour(), 'is_active' => true, 'auto_renew' => true]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('Administrator');
        User::factory()->create()->assignRole('Administrator');
        EmailTemplate::create(['name' => 'x', 'slug' => 'platform-https-expiring', 'trigger_event' => 'platform_https_expiring', 'audience' => 'admin',
            'title' => 't', 'subject' => 'HTTPS {{https.hostname}} {{https.days_left}}', 'body' => 'b', 'admin_recipient_email' => 'ops@example.com']);
        Mail::fake();
    }

    public function test_thresholds_alert_once_each(): void
    {
        $this->cert(60);
        $this->artisan('domaindash:https-check')->assertSuccessful();
        $this->assertSame(0, UserNotification::count());

        foreach ([30 => 1, 29 => 1, 14 => 2, 10 => 2, 7 => 3, 1 => 4, 0 => 4] as $days => $total) {
            $this->cert($days);
            $this->artisan('domaindash:https-check')->assertSuccessful();
            $this->assertSame($total, UserNotification::count(), "after {$days} days left");
        }
        $this->assertDatabaseHas('audit_logs', ['action' => 'https.alert']);
    }

    public function test_new_certificate_resets_alert_state(): void
    {
        $this->cert(5, 'A');
        $this->artisan('domaindash:https-check');
        $this->cert(5, 'B');
        $this->artisan('domaindash:https-check');
        $this->assertSame(2, UserNotification::count());
    }

    public function test_failed_renewal_alerts_once(): void
    {
        $this->cert(60);
        Setting::put(PlatformHttps::LAST_RENEWAL, ['at' => now()->toIso8601String(), 'ok' => false, 'message' => 'dns timeout', 'notified' => false]);
        $this->artisan('domaindash:https-check');
        $this->artisan('domaindash:https-check');
        $this->assertSame(1, UserNotification::where('title', 'Platform HTTPS renewal failed')->count());
    }

    public function test_schedule_registered(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('domaindash:https-check')->assertSuccessful();
    }
}
