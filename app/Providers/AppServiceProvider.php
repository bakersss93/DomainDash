<?php

namespace App\Providers;

use App\Support\Monitoring\ChangeTracker;
use App\Support\Monitoring\SystemEventAuditor;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use App\Support\MailSettings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One client (and one credentials lookup / WSDL parse) per request or
        // queue job; scoped instances are flushed between queued jobs.
        $this->app->scoped(\App\Services\Synergy\SynergyWholesaleClient::class);
        $this->app->scoped(\App\Services\Vocus\VocusWsmClient::class);
        // Monitoring settings + integration request logger: one instance per request / queued job.
        $this->app->scoped(\App\Services\Monitoring\MonitoringSettings::class);
        $this->app->scoped(\App\Services\Monitoring\IntegrationRequestLogger::class);
        $this->app->bind(\App\Services\Https\TxtResolver::class, \App\Services\Https\PhpTxtResolver::class);
        $this->app->bind(\App\Services\Https\AcmeDnsHook::class, fn ($app) => new \App\Services\Https\AcmeDnsHook(
            $app->make(\App\Services\Synergy\SynergyWholesaleClient::class),
            $app->make(\App\Services\Https\TxtResolver::class),
        ));
        $this->app->bind(\App\Services\Https\ZoneFinder::class, fn ($app) => new \App\Services\Https\ZoneFinder(
            $app->make(\App\Services\Synergy\SynergyWholesaleClient::class)
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Links in emails (password reset, invitations) must use APP_URL, never the request's Host header (B1).
        if (! $this->app->environment('local') && ! $this->app->runningUnitTests()) {
            \App\Support\Security\UrlHardening::apply();
        }

        \Illuminate\Validation\Rules\Password::defaults(function () {
            // Strong policy in production; Laravel's default (min 8) elsewhere so local/test data stays easy.
            return $this->app->isProduction()
                ? \Illuminate\Validation\Rules\Password::min(12)->mixedCase()->numbers()->uncompromised()
                : \Illuminate\Validation\Rules\Password::min(8);
        });

        // Pre-auth throttle for the API (before any key lookup / bcrypt work).
        \Illuminate\Support\Facades\RateLimiter::for('api-ip', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(120)->by('api-ip:' . $request->ip());
        });

        // Shared budgets for queued Halo / IT Glue calls across all workers.
        RateLimiter::for('halo-api', fn () => Limit::perMinute((int) config('integrations.halo.rate_limit_per_minute', 120)));
        RateLimiter::for('itglue-api', fn () => Limit::perMinute((int) config('integrations.itglue.rate_limit_per_minute', 400)));

        \App\Models\InternetService::observe(\App\Observers\InternetServiceObserver::class);

        if (Schema::hasTable('settings')) {
            MailSettings::applyFromDatabase();
        }

        // Monitoring: meaningful events only. See App\Support\Monitoring\SystemEventAuditor and docs/review/19-monitoring-logs.md.
        Event::listen(Login::class, function (Login $event): void {
            SystemEventAuditor::auth('auth.login', 'Signed in: ' . $event->user->email, $event->user->email, [
                'user_id' => $event->user->id,
                'remember' => $event->remember,
            ]);
        });

        Event::listen(Failed::class, function (Failed $event): void {
            SystemEventAuditor::auth('auth.login_failed', 'Failed sign-in attempt', $event->credentials['email'] ?? null, [
                'guard' => $event->guard,
            ]);
        });

        Event::listen(\Illuminate\Auth\Events\Logout::class, function ($event): void {
            if ($event->user ?? null) {
                SystemEventAuditor::auth('auth.logout', 'Signed out: ' . $event->user->email, $event->user->email);
            }
        });
        Event::listen(\Illuminate\Auth\Events\Lockout::class, function ($event): void {
            SystemEventAuditor::auth('auth.lockout', 'Sign-in locked out after too many attempts', (string) $event->request->input('email'), ['ip' => $event->request->ip()]);
        });
        Event::listen(\Illuminate\Auth\Events\PasswordReset::class, function ($event): void {
            SystemEventAuditor::auth('auth.password_reset', 'Password reset completed: ' . $event->user->email, $event->user->email);
        });
        foreach ([
            'Laravel\\Fortify\\Events\\TwoFactorAuthenticationEnabled' => ['auth.mfa_enabled', 'MFA enrolment started'],
            'Laravel\\Fortify\\Events\\TwoFactorAuthenticationConfirmed' => ['auth.mfa_confirmed', 'MFA enrolment confirmed'],
            'Laravel\\Fortify\\Events\\TwoFactorAuthenticationDisabled' => ['auth.mfa_disabled', 'MFA disabled'],
            'Laravel\\Fortify\\Events\\TwoFactorAuthenticationFailed' => ['auth.mfa_failed', 'Failed MFA challenge'],
            'Laravel\\Fortify\\Events\\RecoveryCodeReplaced' => ['auth.mfa_recovery_used', 'MFA recovery code used'],
        ] as $class => [$action, $text]) {
            Event::listen($class, function ($event) use ($action, $text): void {
                SystemEventAuditor::auth($action, $text . ': ' . ($event->user->email ?? 'unknown user'), $event->user->email ?? null);
            });
        }

        // Eloquent changes made during a mutating HTTP request feed the old -> new diff of its activity row.
        foreach (['created', 'updated', 'deleted'] as $eloquentEvent) {
            Event::listen("eloquent.{$eloquentEvent}: *", function (string $name, array $payload) use ($eloquentEvent): void {
                ChangeTracker::record($eloquentEvent, $payload[0] ?? null);
            });
        }

        Event::listen(CommandStarting::class, [SystemEventAuditor::class, 'commandStarting']);
        Event::listen(CommandFinished::class, [SystemEventAuditor::class, 'commandFinished']);
        Event::listen(ScheduledTaskStarting::class, [SystemEventAuditor::class, 'taskStarting']);
        Event::listen(ScheduledTaskFinished::class, [SystemEventAuditor::class, 'taskFinished']);
        Event::listen(ScheduledTaskSkipped::class, [SystemEventAuditor::class, 'taskSkipped']);
        Event::listen(ScheduledTaskFailed::class, [SystemEventAuditor::class, 'taskFailed']);
        Event::listen(JobProcessing::class, [SystemEventAuditor::class, 'jobProcessing']);
        Event::listen(JobProcessed::class, [SystemEventAuditor::class, 'jobProcessed']);
        Event::listen(JobFailed::class, [SystemEventAuditor::class, 'jobFailed']);
    }
}
