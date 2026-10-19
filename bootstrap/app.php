<?php

use App\Console\ScheduleRegistrar;
use Illuminate\Foundation\Application;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role'              => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission'        => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission'=> \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'mfa.policy'        => \App\Http\Middleware\EnsureMfaPolicy::class,
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\ApplyImpersonation::class,
            \App\Http\Middleware\AuditRequestActions::class,
        ]);

        // Per-IP throttle runs BEFORE API-key authentication (SEC-06).
        $middleware->api(prepend: [
            'throttle:api-ip',
        ]);

        $middleware->api(append: [
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\AuditRequestActions::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule) {
        ScheduleRegistrar::register($schedule);
    })
        
    ->withExceptions(function (Exceptions $exceptions) {
        // API clients always get JSON (e.g. 422 validation errors) regardless of Accept.
        $exceptions->shouldRenderJsonWhen(function (\Illuminate\Http\Request $request, \Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();
