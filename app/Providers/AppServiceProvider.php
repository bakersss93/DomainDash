<?php

namespace App\Providers;

use App\Services\AuditLogger;
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

        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            if (! $this->shouldAuditConsoleCommand($event->command)) {
                return;
            }
            if (! Schema::hasTable('audit_logs')) {
                return;
            }

            AuditLogger::logSystem(
                'console.command_started',
                sprintf('Console command started: %s', $event->command ?? 'unknown'),
                [
                    'function' => 'console-command',
                    'service' => 'artisan',
                    'automated' => app()->runningInConsole(),
                ],
                [
                    'new_values' => [
                        'command' => $event->command,
                        'input' => $event->input?->getArguments(),
                    ],
                ]
            );
        });

        Event::listen(CommandFinished::class, function (CommandFinished $event): void {
            if (! $this->shouldAuditConsoleCommand($event->command)) {
                return;
            }
            if (! Schema::hasTable('audit_logs')) {
                return;
            }

            AuditLogger::logSystem(
                'console.command_finished',
                sprintf('Console command finished: %s', $event->command ?? 'unknown'),
                [
                    'function' => 'console-command',
                    'service' => 'artisan',
                    'automated' => app()->runningInConsole(),
                ],
                [
                    'new_values' => [
                        'command' => $event->command,
                        'exit_code' => $event->exitCode,
                    ],
                ]
            );
        });

        Event::listen(ScheduledTaskStarting::class, function (ScheduledTaskStarting $event): void {
            AuditLogger::logSystem(
                'scheduler.task_started',
                sprintf('Scheduled task started: %s', $event->task->description),
                [
                    'function' => 'scheduler-task',
                    'service' => 'scheduler',
                    'automated' => true,
                ],
                [
                    'new_values' => [
                        'description' => $event->task->description,
                        'command' => $event->task->command,
                        'expression' => $event->task->expression,
                    ],
                ]
            );
        });

        Event::listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event): void {
            AuditLogger::logSystem(
                'scheduler.task_finished',
                sprintf('Scheduled task finished: %s', $event->task->description),
                [
                    'function' => 'scheduler-task',
                    'service' => 'scheduler',
                    'automated' => true,
                ],
                [
                    'new_values' => [
                        'description' => $event->task->description,
                        'command' => $event->task->command,
                        'expression' => $event->task->expression,
                        'runtime_seconds' => $event->runtime,
                        'exit_code' => $event->task->exitCode,
                    ],
                ]
            );
        });

        Event::listen(ScheduledTaskSkipped::class, function (ScheduledTaskSkipped $event): void {
            AuditLogger::logSystem(
                'scheduler.task_skipped',
                sprintf('Scheduled task skipped: %s', $event->task->description),
                [
                    'function' => 'scheduler-task',
                    'service' => 'scheduler',
                    'automated' => true,
                ],
                [
                    'new_values' => [
                        'description' => $event->task->description,
                        'command' => $event->task->command,
                        'expression' => $event->task->expression,
                    ],
                ]
            );
        });

        Event::listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event): void {
            AuditLogger::logSystem(
                'scheduler.task_failed',
                sprintf('Scheduled task failed: %s', $event->task->description),
                [
                    'function' => 'scheduler-task',
                    'service' => 'scheduler',
                    'automated' => true,
                ],
                [
                    'new_values' => [
                        'description' => $event->task->description,
                        'command' => $event->task->command,
                        'expression' => $event->task->expression,
                        'error' => $event->exception->getMessage(),
                    ],
                ]
            );
        });

        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            AuditLogger::logSystem(
                'queue.job_started',
                sprintf('Queue job started: %s', $event->job->resolveName()),
                [
                    'function' => 'queue-job',
                    'service' => 'queue',
                    'automated' => true,
                ],
                [
                    'new_values' => [
                        'connection' => $event->connectionName,
                        'queue' => $event->job->getQueue(),
                        'job_name' => $event->job->resolveName(),
                    ],
                ]
            );
        });

        Event::listen(JobProcessed::class, function (JobProcessed $event): void {
            AuditLogger::logSystem(
                'queue.job_finished',
                sprintf('Queue job finished: %s', $event->job->resolveName()),
                [
                    'function' => 'queue-job',
                    'service' => 'queue',
                    'automated' => true,
                ],
                [
                    'new_values' => [
                        'connection' => $event->connectionName,
                        'queue' => $event->job->getQueue(),
                        'job_name' => $event->job->resolveName(),
                    ],
                ]
            );
        });

        Event::listen(JobFailed::class, function (JobFailed $event): void {
            AuditLogger::logSystem(
                'queue.job_failed',
                sprintf('Queue job failed: %s', $event->job->resolveName()),
                [
                    'function' => 'queue-job',
                    'service' => 'queue',
                    'automated' => true,
                ],
                [
                    'new_values' => [
                        'connection' => $event->connectionName,
                        'queue' => $event->job->getQueue(),
                        'job_name' => $event->job->resolveName(),
                        'error' => $event->exception->getMessage(),
                    ],
                ]
            );
        });
    }

    private function shouldAuditConsoleCommand(?string $command): bool
    {
        if ($command === null) {
            return true;
        }

        return ! in_array($command, ['schedule:run', 'schedule:work'], true);
    }
}
