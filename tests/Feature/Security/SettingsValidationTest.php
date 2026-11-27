<?php

namespace Tests\Feature\Security;

use App\Models\Setting;
use App\Models\User;
use App\Services\Sync\SynergyDomainData;
use App\Support\Security\TrustedWsdl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SettingsValidationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::findOrCreate('Administrator', 'web');
        $u = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
        $u->assignRole('Administrator');

        return $u;
    }

    private function save(array $data)
    {
        return $this->actingAs($this->admin())->post(route('admin.settings.update'), $data);
    }

    public function test_smtp_host_rejects_private_loopback_and_junk(): void
    {
        foreach (['127.0.0.1', '10.0.0.5', '169.254.169.254', 'localhost', 'http://evil.com', 'evil.com/path', 'a b.com'] as $host) {
            $this->save(['smtp' => ['host' => $host]])->assertSessionHasErrors('smtp.host');
        }
        $this->assertNull(Setting::get('smtp'));
    }

    public function test_smtp_accepts_a_public_host_and_validates_other_fields(): void
    {
        $this->save(['smtp' => ['host' => '8.8.8.8', 'port' => 587, 'encryption' => 'tls', 'from' => 'a@example.com', 'from_name' => 'DD']])
            ->assertSessionHasNoErrors();
        $this->assertSame(587, (int) Setting::get('smtp')['port']);

        $this->save(['smtp' => ['port' => 99999]])->assertSessionHasErrors('smtp.port');
        $this->save(['smtp' => ['encryption' => 'starttls-evil']])->assertSessionHasErrors('smtp.encryption');
        $this->save(['smtp' => ['from' => 'not-an-email']])->assertSessionHasErrors('smtp.from');
    }

    public function test_private_hosts_can_be_allowed_explicitly_by_the_server_owner(): void
    {
        config(['domaindash.allow_private_hosts' => true]);
        $this->save(['smtp' => ['host' => '10.0.0.5']])->assertSessionHasNoErrors();
    }

    public function test_unknown_keys_in_validated_groups_are_rejected(): void
    {
        $this->save(['smtp' => ['host' => '8.8.8.8', 'transport' => 'sendmail']])->assertSessionHasErrors('smtp');
        $this->save(['synergy' => ['endpoint' => 'https://evil.example']])->assertSessionHasErrors('synergy');
    }

    public function test_backup_settings_validation(): void
    {
        $this->save(['backup' => ['host' => '192.168.1.10']])->assertSessionHasErrors('backup.host');
        $this->save(['backup' => ['host_fingerprint' => "SHA256:abc; rm -rf"]])->assertSessionHasErrors('backup.host_fingerprint');
        $this->save(['backup' => ['encryption_password' => 'short']])->assertSessionHasErrors('backup.encryption_password');
        $this->save(['backup' => ['path' => '/a/../../etc']])->assertSessionHasErrors('backup.path');

        $this->save(['backup' => ['host' => '8.8.4.4', 'host_fingerprint' => 'SHA256:AbC+/=', 'encryption_password' => 'a-long-enough-password', 'path' => '/backups']])
            ->assertSessionHasNoErrors();
        $this->assertSame('a-long-enough-password', Setting::get('backup')['encryption_password']);

        // The form placeholder keeps the stored password and is not treated as "too short".
        $this->save(['backup' => ['encryption_password' => '********', 'retention' => 5]])->assertSessionHasNoErrors();
        $this->assertSame('a-long-enough-password', Setting::get('backup')['encryption_password']);
    }

    public function test_synergy_wsdl_path_must_be_trusted(): void
    {
        $this->save(['synergy' => ['wsdl_path' => 'http://169.254.169.254/x']])->assertSessionHasErrors('synergy.wsdl_path');
        $this->save(['synergy' => ['wsdl_path' => 'file:///etc/passwd']])->assertSessionHasErrors('synergy.wsdl_path');
        $this->save(['synergy' => ['wsdl_path' => '/etc/passwd']])->assertSessionHasErrors('synergy.wsdl_path');
        $this->save(['synergy' => ['wsdl_path' => 'https://evil.com/x.wsdl']])->assertSessionHasErrors('synergy.wsdl_path');

        $this->save(['synergy' => ['wsdl_path' => 'https://api.synergywholesale.com/?wsdl', 'reseller_id' => '123', 'api_key' => 'k']])
            ->assertSessionHasNoErrors();
        $this->save(['synergy' => ['wsdl_path' => base_path('resources/wsdl/synergy.wsdl')]])->assertSessionHasNoErrors();
    }

    public function test_sync_lookups_use_the_same_trusted_wsdl_resolution(): void
    {
        $default = (string) config('services.synergy.wsdl');
        $this->assertSame($default, TrustedWsdl::resolve('http://169.254.169.254/x'));
        $this->assertSame($default, TrustedWsdl::resolve('/etc/passwd'));
        $this->assertSame('https://api.synergywholesale.com/?wsdl', TrustedWsdl::resolve('https://api.synergywholesale.com/?wsdl'));
        $this->assertTrue(method_exists(SynergyDomainData::class, 'dnsRecords'));
    }

    public function test_vocus_sandbox_ip_must_be_public(): void
    {
        foreach (['10.1.2.3', '127.0.0.1', '192.168.0.9', '169.254.1.1'] as $ip) {
            $this->save(['vocus' => ['sandbox_ip' => $ip]])->assertSessionHasErrors('vocus.sandbox_ip');
        }
        $this->save(['vocus' => ['sandbox_ip' => '8.8.8.8']])->assertSessionHasNoErrors();
    }

    public function test_branding_colours_must_be_hex(): void
    {
        $this->save(['branding' => ['primary' => 'red;}</style><script>']])->assertSessionHasErrors('branding.primary');
        $this->save(['branding' => ['primary' => '#112233', 'accent_dark' => '#abcdef']])->assertSessionHasNoErrors();
        $this->save(['branding' => ['logo' => 'x']])->assertSessionHasErrors('branding');
    }

    public function test_ip2whois_key_is_validated(): void
    {
        $this->save(['ip2whois' => ['api_key' => str_repeat('a', 300)]])->assertSessionHasErrors('ip2whois.api_key');
        $this->save(['ip2whois' => ['api_key' => 'abc']])->assertSessionHasNoErrors();
    }
}
