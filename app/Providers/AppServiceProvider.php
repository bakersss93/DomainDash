<?php

namespace App\Providers;

use App\Services\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use App\Support\MailSettings;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (Schema::hasTable('settings')) {
            MailSettings::applyFromDatabase();
        }

        Event::listen(Login::class, function (Login $event): void {
            AuditLogger::logSystem(
                'auth.login',
                'User logged in successfully.',
                [
                    'function' => 'authentication',
                    'service' => 'auth',
                    'user_id' => $event->user->id,
                ],
                [
                    'user_email' => $event->user->email,
                    'new_values' => [
                        'remember' => $event->remember,
                    ],
                ]
            );
        });

        Event::listen(Failed::class, function (Failed $event): void {
            AuditLogger::logSystem(
                'auth.login_failed',
                'Failed login attempt.',
                [
                    'function' => 'authentication',
                    'service' => 'auth',
                ],
                [
                    'user_email' => $event->credentials['email'] ?? null,
                    'new_values' => [
                        'guard' => $event->guard,
                        'email' => $event->credentials['email'] ?? null,
                    ],
                ]
            );
        });
    }
}
