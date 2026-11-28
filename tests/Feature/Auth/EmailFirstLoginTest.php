<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Services\Microsoft\MicrosoftSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\Sso\SsoTestCase;

class EmailFirstLoginTest extends SsoTestCase
{
    private const TID = 'aaaaaaaa-1111-2222-3333-444444444444';

    private function microsoftOn(): void
    {
        Setting::put(MicrosoftSettings::KEY, ['enabled' => true, 'client_id' => '11111111-aaaa-bbbb-cccc-222222222222', 'client_secret' => 's3cret', 'allowed_tenants' => []]);
    }

    private function staff(string $email = 'tina@contoso.com', array $attrs = []): User
    {
        $u = User::factory()->create($attrs + ['email' => $email, 'sso_enabled' => true]);
        $u->assignRole('Technician');

        return $u;
    }

    private function client(string $email = 'amy@acme.com', array $attrs = [], ?string $tenant = self::TID): User
    {
        $u = User::factory()->create($attrs + ['email' => $email, 'sso_enabled' => true]);
        $u->assignRole('Customer');
        $u->clients()->attach(Client::create(['business_name' => 'Acme', 'microsoft_tenant_id' => $tenant])->id);

        return $u;
    }

    private function hasRecaller($response): bool
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if (str_starts_with($cookie->getName(), 'remember_web_')) {
                return true;
            }
        }

        return false;
    }

    /** Step 1 then the rendered page; returns the HTML. */
    private function stepFor(string $email, ?string $mode = null): string
    {
        $this->post(route('login.identify'), ['email' => $email])->assertRedirect(route('login'));
        if ($mode) {
            $this->get(route('login.mode', $mode))->assertRedirect(route('login'));
        }

        return $this->get(route('login'))->assertOk()->getContent();
    }

    private function state(string $html): string
    {
        preg_match('/id="loginForm" data-state="([a-z-]+)"/', $html, $m);

        return $m[1] ?? 'none';
    }

    public function test_step_one_shows_email_only_and_no_always_on_microsoft_button(): void
    {
        $this->staffConnection();
        $this->microsoftOn();

        $html = $this->get(route('login'))->assertOk()->assertDontSee('Sign in with Microsoft')->getContent();
        $this->assertSame('email', $this->state($html));
        $this->assertStringContainsString('Continue', $html);
    }

    public function test_unknown_and_non_sso_emails_get_the_same_password_step(): void
    {
        $this->staffConnection();
        $this->microsoftOn();
        $this->staff('plain@contoso.com', ['sso_enabled' => false]);

        $unknown = $this->stepFor('nobody@contoso.com');
        $plain = $this->stepFor('plain@contoso.com');

        $this->assertSame('password', $this->state($unknown));
        $this->assertSame('password', $this->state($plain));
        $norm = fn (string $h, string $e) => preg_replace('/name="_token" value="[^"]+"/', '', str_replace($e, 'EMAIL', $h));
        $this->assertSame($norm($unknown, 'nobody@contoso.com'), $norm($plain, 'plain@contoso.com'), 'pages must be byte-identical apart from the email');
    }

    public function test_inactive_or_unavailable_sso_accounts_look_like_non_sso(): void
    {
        $this->microsoftOn();
        $c = $this->staffConnection();
        $this->staff('off@contoso.com', ['is_active' => false]);
        $this->staff('ok@contoso.com');
        $this->client('notenant@acme.com', [], '');

        $this->assertSame('password', $this->state($this->stepFor('off@contoso.com')));
        $this->assertSame('password', $this->state($this->stepFor('notenant@acme.com')));

        $c->update(['enabled' => false]);
        $this->assertSame('password', $this->state($this->stepFor('ok@contoso.com')));
    }

    public function test_sso_staff_sees_single_sign_on_with_optional_password(): void
    {
        $this->staffConnection();
        $this->staff();
        $html = $this->stepFor('tina@contoso.com');

        $this->assertSame('sso-both', $this->state($html));
        $this->assertStringContainsString('Single sign-on', $html);
        $this->assertStringContainsString('Log in with Microsoft', $html);
        $this->assertStringContainsString('Use password instead', $html);
        $this->assertStringContainsString('Keep me logged in', $html);

        // Staff policy "Microsoft only" and no personal exception: no password route is offered.
        Setting::put('sso', ['staff_login' => 'microsoft_only']);
        $this->assertSame('sso', $this->state($this->stepFor('tina@contoso.com')));
        $this->assertSame('sso', $this->state($this->stepFor('tina@contoso.com', 'password')), 'the server ignores "use password" when the policy forbids it');
    }

    public function test_sso_client_and_sso_plus_password_views(): void
    {
        $this->microsoftOn();
        $this->client();

        $this->assertSame('sso-both', $this->state($this->stepFor('amy@acme.com')));

        $html = $this->stepFor('amy@acme.com', 'password');
        $this->assertSame('password-sso', $this->state($html));
        $this->assertStringContainsString('Log In with Single Sign-On', $html);
        $this->assertStringContainsString('Forgot password?', $html);
        $this->assertStringContainsString('Log in to', $html);
        $this->assertStringNotContainsString('Acme', $html, 'the client name must never appear on the login page');

        Setting::put('sso', ['client_login' => 'microsoft_only']);
        $this->assertSame('sso', $this->state($this->stepFor('amy@acme.com')));
    }

    public function test_sso_requires_the_per_user_switch_to_be_shown(): void
    {
        $this->microsoftOn();
        $this->client('amy@acme.com', ['sso_enabled' => false]);

        $this->assertSame('password', $this->state($this->stepFor('amy@acme.com')));
    }

    public function test_heading_uses_platform_branding_name_not_client_name(): void
    {
        Setting::put('branding', ['name' => 'Jargon Portal']);
        $this->assertStringContainsString('Log in to Jargon Portal', $this->stepFor('x@y.com'));
    }

    public function test_sso_action_sends_staff_to_saml_and_keeps_remember_through_the_round_trip(): void
    {
        $c = $this->staffConnection(['default_role' => 'Technician']);
        $user = $this->staff();

        $res = $this->post(route('login.sso'), ['email' => 'tina@contoso.com', 'remember' => '1']);
        $res->assertRedirect(route('saml.login', $c->uuid));
        $this->assertTrue(session('sso.remember'));

        $id = $this->startLogin($c); // GET saml.login consumes the flag into the pending request
        $this->assertNull(session('sso.remember'));
        $this->assertSame(1, (int) DB::table('saml_pending_requests')->where('request_id', $id)->value('remember'));

        $b64 = \Tests\Support\SamlFixture::response($c, $this->idpKey, ['inResponseTo' => $id, 'email' => 'tina@contoso.com']);
        $res = $this->post($c->acsUrl(), ['SAMLResponse' => $b64])->assertRedirect();
        $this->assertAuthenticatedAs($user->fresh());
        $this->assertTrue($this->hasRecaller($res), 'keep me logged in must survive the SAML round trip');
    }

    public function test_saml_without_remember_does_not_set_a_remember_token(): void
    {
        $c = $this->staffConnection(['default_role' => 'Technician']);
        $user = $this->staff();

        $res = $this->ssoLogin($c, ['email' => 'tina@contoso.com'])->assertRedirect();
        $this->assertAuthenticatedAs($user->fresh());
        $this->assertFalse($this->hasRecaller($res));
    }

    public function test_sso_action_sends_clients_to_microsoft_with_a_hint_and_remembers_the_choice(): void
    {
        $this->microsoftOn();
        $this->client();

        $res = $this->post(route('login.sso'), ['email' => 'amy@acme.com', 'remember' => '1']);
        $this->assertStringContainsString('login.microsoftonline.com/organizations/oauth2/v2.0/authorize', $res->headers->get('Location'));
        $this->assertStringContainsString('login_hint=amy%40acme.com', $res->headers->get('Location'));
        $this->assertTrue(session('sso.remember'));
    }

    public function test_sso_action_refuses_everyone_else_generically_and_audits_sso_not_enabled(): void
    {
        $this->staffConnection();
        $this->staff('off@contoso.com', ['sso_enabled' => false]);

        $a = $this->post(route('login.sso'), ['email' => 'off@contoso.com']);
        $b = $this->post(route('login.sso'), ['email' => 'ghost@contoso.com']);

        $a->assertRedirect(route('login'))->assertSessionHasErrors(['email' => 'Single sign-on is not available for this account.']);
        $b->assertRedirect(route('login'))->assertSessionHasErrors(['email' => 'Single sign-on is not available for this account.']);
        $this->assertTrue(AuditLog::where('action', 'sso.login.failed')->where('user_email', 'off@contoso.com')->exists());
        $this->assertFalse(AuditLog::where('action', 'sso.login.failed')->where('user_email', 'ghost@contoso.com')->exists());
    }

    public function test_step_one_is_throttled_per_ip_and_email(): void
    {
        RateLimiter::clear('x');
        for ($i = 0; $i < 10; $i++) {
            $this->post(route('login.identify'), ['email' => 'a@b.com'])->assertRedirect(route('login'));
        }
        $this->post(route('login.identify'), ['email' => 'a@b.com'])->assertStatus(429);
        $this->post(route('login.identify'), ['email' => 'other@b.com'])->assertRedirect(route('login'));
    }

    public function test_step_one_validates_the_email_the_same_way_for_everyone(): void
    {
        $this->post(route('login.identify'), ['email' => 'not-an-email'])->assertSessionHasErrors('email');
    }

    public function test_use_a_different_email_clears_the_step(): void
    {
        $this->stepFor('tina@contoso.com');
        $this->get(route('login.reset'))->assertRedirect(route('login'));
        $this->assertSame('email', $this->state($this->get(route('login'))->getContent()));
    }

    public function test_a_failed_password_attempt_returns_to_the_password_step(): void
    {
        $this->staff('plain@contoso.com', ['sso_enabled' => false]);
        $this->post(route('login'), ['email' => 'plain@contoso.com', 'password' => 'wrong'])->assertRedirect();

        $this->assertSame('password', $this->state($this->get(route('login'))->getContent()));
    }

    // ---- lookup endpoint used for the in-place switch ----------------------------------------------------------

    public function test_lookup_answers_are_identical_for_unknown_non_sso_inactive_and_unavailable(): void
    {
        $this->staffConnection();
        $this->microsoftOn();
        $this->staff('plain@contoso.com', ['sso_enabled' => false]);
        $this->staff('off@contoso.com', ['is_active' => false]);
        $this->client('notenant@acme.com', [], '');

        $bodies = [];
        foreach (['ghost@nowhere.org', 'plain@contoso.com', 'off@contoso.com', 'notenant@acme.com', 'not-an-email', ''] as $email) {
            $res = $this->postJson(route('login.method'), ['email' => $email])->assertOk();
            $bodies[] = [$res->getContent(), $res->headers->get('Content-Type')];
        }
        $this->assertSame(['{"method":"password"}'], array_values(array_unique(array_column($bodies, 0))));
        $this->assertCount(1, array_unique(array_column($bodies, 1)));
    }

    public function test_lookup_reports_sso_for_enabled_accounts_only_with_password_flag(): void
    {
        $this->staffConnection();
        $this->microsoftOn();
        $this->staff();
        $this->client();

        $this->postJson(route('login.method'), ['email' => 'Tina@Contoso.com'])->assertExactJson(['method' => 'sso', 'password' => true]);
        $this->postJson(route('login.method'), ['email' => 'amy@acme.com'])->assertExactJson(['method' => 'sso', 'password' => true]);

        Setting::put('sso', ['staff_login' => 'microsoft_only']);
        $this->postJson(route('login.method'), ['email' => 'tina@contoso.com'])->assertExactJson(['method' => 'sso', 'password' => false]);
    }

    public function test_lookup_has_a_uniform_minimum_duration_and_calls_no_external_service(): void
    {
        \Illuminate\Support\Facades\Http::fake();
        $this->staffConnection();
        $this->staff();

        foreach (['ghost@nowhere.org', 'tina@contoso.com'] as $email) {
            $t = microtime(true);
            $this->postJson(route('login.method'), ['email' => $email])->assertOk();
            $this->assertGreaterThanOrEqual(0.1, microtime(true) - $t);
        }
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_lookup_is_throttled_per_ip(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->postJson(route('login.method'), ['email' => "u{$i}@x.com"])->assertOk();
        }
        $this->postJson(route('login.method'), ['email' => 'u@x.com'])->assertStatus(429);
    }

    public function test_lookup_is_only_for_guests(): void
    {
        $this->actingAs($this->staff('in@contoso.com'))->postJson(route('login.method'), ['email' => 'a@b.com'])->assertRedirect();
    }
}
