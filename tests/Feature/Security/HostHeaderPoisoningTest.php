<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Support\Security\UrlHardening;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class HostHeaderPoisoningTest extends TestCase
{
    use RefreshDatabase;

    public function test_trusted_host_is_exactly_the_app_url_host(): void
    {
        config(['app.url' => 'https://Dash.Example.com']);

        $patterns = UrlHardening::trustedHostPatterns();
        $this->assertCount(1, $patterns);
        $this->assertSame(1, preg_match('{' . $patterns[0] . '}i', 'dash.example.com'));
        $this->assertSame(0, preg_match('{' . $patterns[0] . '}i', 'evil.example.com'));
        $this->assertSame(0, preg_match('{' . $patterns[0] . '}i', 'sub.dash.example.com'));
        $this->assertSame(0, preg_match('{' . $patterns[0] . '}i', 'dash.example.com.evil.net'));
    }

    public function test_no_app_url_host_means_no_restriction_pattern(): void
    {
        config(['app.url' => '']);
        $this->assertSame([], UrlHardening::trustedHostPatterns());
    }

    public function test_missing_app_url_host_fails_closed_in_production(): void
    {
        config(['app.url' => '']);
        $this->app['env'] = 'production';

        $this->expectException(\RuntimeException::class);
        UrlHardening::trustedHostPatterns();
    }

    public function test_password_reset_link_uses_app_url_even_with_a_forged_host_header(): void
    {
        config(['app.url' => 'https://dash.example.com']);
        UrlHardening::apply();
        Notification::fake();

        $user = User::factory()->create();

        $this->withHeaders(['Host' => 'evil.example.net', 'X-Forwarded-Host' => 'evil.example.net'])
            ->post('http://evil.example.net/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user) {
            $url = $n->toMail($user)->actionUrl;
            $this->assertStringStartsWith('https://dash.example.com/', $url);
            $this->assertStringNotContainsString('evil.example.net', $url);

            return true;
        });
    }

    public function test_apply_forces_https_scheme_only_when_app_url_is_https(): void
    {
        config(['app.url' => 'https://dash.example.com']);
        UrlHardening::apply();
        $this->assertSame('https://dash.example.com/login', url('/login'));
    }

    public function test_nginx_example_has_a_catch_all_that_drops_unknown_hosts(): void
    {
        $conf = file_get_contents(base_path('deploy/https/nginx-domaindash.conf.example'));
        $this->assertStringContainsString('default_server', $conf);
        $this->assertStringContainsString('return 444;', $conf);
    }
}
