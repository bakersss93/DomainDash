<?php

namespace Tests\Feature\Security;

use App\Listeners\RefuseSensitiveMailViaLogMailer;
use App\Models\AuditLog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class LoggingMailSessionDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_config_defaults_are_safe_when_env_does_not_override(): void
    {
        // Evaluate the config files with no environment overrides.
        foreach (['LOG_STACK', 'LOG_LEVEL', 'SESSION_ENCRYPT'] as $k) {
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        }
        $logging = require base_path('config/logging.php');
        $session = require base_path('config/session.php');

        $this->assertSame(['daily'], $logging['channels']['stack']['channels']);
        $this->assertSame('warning', $logging['channels']['daily']['level']);
        $this->assertSame('warning', $logging['channels']['single']['level']);
        $this->assertTrue($session['encrypt']);
    }

    public function test_failed_jobs_are_pruned_on_a_schedule(): void
    {
        $schedule = new Schedule();
        \App\Console\ScheduleRegistrar::register($schedule);

        $events = collect($schedule->events())->filter(fn ($e) => str_contains((string) $e->command, 'queue:prune-failed'));
        $this->assertCount(1, $events);
        $this->assertStringContainsString('--hours=720', (string) $events->first()->command);
    }

    private function resetMessage(): Email
    {
        return (new Email())->from('a@example.com')->to('victim@example.com')->subject('Reset')
            ->text('Reset your password: https://dash.example.com/reset-password/abc123?email=victim@example.com');
    }

    public function test_reset_mail_through_the_log_mailer_is_refused_in_production(): void
    {
        $this->app->instance('env', 'production');
        config(['mail.default' => 'log']);
        Log::shouldReceive('critical')->once();

        $result = (new RefuseSensitiveMailViaLogMailer())->handle(new MessageSending($this->resetMessage()));

        $this->assertFalse($result);
        $this->assertTrue(AuditLog::where('action', 'mail.refused')->exists());
        $this->assertStringNotContainsString('abc123', json_encode(AuditLog::where('action', 'mail.refused')->first()->toArray()));
    }

    public function test_dispatching_the_event_cancels_the_send(): void
    {
        $this->app->instance('env', 'production');
        config(['mail.default' => 'log']);

        $this->assertFalse(event(new MessageSending($this->resetMessage()), [], true) ?? true);
    }

    public function test_real_transports_and_non_production_are_untouched(): void
    {
        $listener = new RefuseSensitiveMailViaLogMailer();

        $this->app->instance('env', 'production');
        config(['mail.default' => 'smtp']);
        $this->assertNull($listener->handle(new MessageSending($this->resetMessage())));

        $this->app->instance('env', 'local');
        config(['mail.default' => 'log']);
        $this->assertNull($listener->handle(new MessageSending($this->resetMessage())));

        // Non-sensitive mail (e.g. expiry notices) is not blocked even on the log mailer in production.
        $this->app->instance('env', 'production');
        $plain = (new Email())->from('a@example.com')->to('b@example.com')->subject('Expiry')->text('Your domain expires soon.');
        $this->assertNull($listener->handle(new MessageSending($plain)));
    }
}
