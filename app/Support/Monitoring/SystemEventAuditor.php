<?php

namespace App\Support\Monitoring;

use App\Services\AuditLogger;
use App\Services\Monitoring\MonitoringSettings;
use App\Support\SyncErrorSanitizer;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Cache;

/**
 * Console / scheduler / queue events, reduced to what an operator cares about.
 *
 * Recorded: failures (throttled per command / job so a failing every-minute task is one row per window) and
 * unusually slow runs. Routine start / finish / skipped / heartbeat events are dropped unless Monitoring >
 * Verbose is on. Sync outcomes are tracked by sync_runs (Jobs & Schedules tab), not by per-job events.
 */
class SystemEventAuditor
{
    /** @var array<string, float> */
    private static array $started = [];

    // -- console ---------------------------------------------------------

    public static function commandStarting(CommandStarting $event): void
    {
        if ($event->command !== null) {
            self::$started[$event->command] = microtime(true); // memory only, no I/O
        }
    }

    public static function commandFinished(CommandFinished $event): void
    {
        self::guard(function () use ($event) {
            $command = $event->command;
            if ($command === null) {
                return;
            }

            $startedAt = self::$started[$command] ?? null;
            unset(self::$started[$command]);
            $runtime = $startedAt !== null ? microtime(true) - $startedAt : null;

            $verbose = self::verbose();
            if (! $verbose && in_array($command, (array) config('monitoring.silent_commands', []), true)) {
                return;
            }

            $ours = str_starts_with($command, 'domaindash:');
            $failed = $event->exitCode !== 0;
            $slow = $ours && $runtime !== null && $runtime >= (int) config('monitoring.slow_seconds', 120);

            if (! $verbose && ! (($failed && $ours) || $slow)) {
                return;
            }
            if ($failed && ! $verbose && self::throttled('cmd:' . $command)) {
                return;
            }

            $action = $failed ? 'console.command_failed' : ($slow ? 'console.command_slow' : 'console.command_finished');
            $text = $failed ? "Command {$command} failed (exit code {$event->exitCode})"
                : ($slow ? sprintf('Command %s took %ds', $command, (int) $runtime) : "Command {$command} finished");

            AuditLogger::logSystem($action, $text, ['function' => 'console-command', 'service' => 'artisan', 'automated' => true], [
                'new_values' => ['command' => $command, 'exit_code' => $event->exitCode, 'runtime_seconds' => $runtime !== null ? round($runtime, 1) : null],
            ]);
        });
    }

    // -- scheduler -------------------------------------------------------

    public static function taskStarting(ScheduledTaskStarting $event): void
    {
        self::guard(function () use ($event) {
            if (self::verbose()) {
                self::task('scheduler.task_started', 'Scheduled task started', $event->task);
            }
        });
    }

    public static function taskFinished(ScheduledTaskFinished $event): void
    {
        self::guard(function () use ($event) {
            $slow = $event->runtime >= (int) config('monitoring.slow_seconds', 120);
            if ($slow || self::verbose()) {
                self::task($slow ? 'scheduler.task_slow' : 'scheduler.task_finished', $slow ? 'Scheduled task was slow' : 'Scheduled task finished', $event->task, ['runtime_seconds' => round($event->runtime, 1), 'exit_code' => $event->task->exitCode]);
            }
        });
    }

    public static function taskSkipped(ScheduledTaskSkipped $event): void
    {
        self::guard(function () use ($event) {
            if (self::verbose()) {
                self::task('scheduler.task_skipped', 'Scheduled task skipped', $event->task);
            }
        });
    }

    public static function taskFailed(ScheduledTaskFailed $event): void
    {
        self::guard(function () use ($event) {
            $name = (string) ($event->task->description ?: $event->task->command);
            if (! self::verbose() && self::throttled('task:' . $name)) {
                return;
            }
            self::task('scheduler.task_failed', 'Scheduled task failed', $event->task, ['error' => SyncErrorSanitizer::describe($event->exception, 400)]);
        });
    }

    private static function task(string $action, string $prefix, $task, array $extra = []): void
    {
        AuditLogger::logSystem($action, $prefix . ': ' . ($task->description ?: $task->command), ['function' => 'scheduler-task', 'service' => 'scheduler', 'automated' => true], [
            'new_values' => $extra + ['expression' => $task->expression],
        ]);
    }

    // -- queue -----------------------------------------------------------

    public static function jobProcessing(JobProcessing $event): void
    {
        self::guard(function () use ($event) {
            if (self::verbose()) {
                self::job('queue.job_started', 'Queue job started', $event);
            }
        });
    }

    public static function jobProcessed(JobProcessed $event): void
    {
        self::guard(function () use ($event) {
            if (self::verbose()) {
                self::job('queue.job_finished', 'Queue job finished', $event);
            }
        });
    }

    public static function jobFailed(JobFailed $event): void
    {
        self::guard(function () use ($event) {
            $name = $event->job->resolveName();
            $verbose = self::verbose();
            if (! $verbose && in_array($name, (array) config('monitoring.silent_jobs', []), true)) {
                return;
            }
            if (! $verbose && self::throttled('job:' . $name)) {
                return;
            }
            self::job('queue.job_failed', 'Queue job failed', $event, ['error' => SyncErrorSanitizer::describe($event->exception, 400)]);
        });
    }

    private static function job(string $action, string $prefix, $event, array $extra = []): void
    {
        $name = $event->job->resolveName();
        AuditLogger::logSystem($action, $prefix . ': ' . class_basename($name), ['function' => 'queue-job', 'service' => 'queue', 'automated' => true], [
            'new_values' => $extra + ['connection' => $event->connectionName, 'queue' => $event->job->getQueue(), 'job_name' => $name],
        ]);
    }

    // -- auth / security --------------------------------------------------

    public static function auth(string $action, string $description, ?string $email, array $new = []): void
    {
        self::guard(function () use ($action, $description, $email, $new) {
            AuditLogger::logSystem($action, $description, ['function' => 'authentication', 'service' => 'auth'], [
                'user_email' => $email,
                'new_values' => $new ?: null,
            ]);
        });
    }

    // -- helpers ----------------------------------------------------------

    private static function verbose(): bool
    {
        return app(MonitoringSettings::class)->verbose();
    }

    /** True when an identical failure was already recorded inside the throttle window. */
    private static function throttled(string $key): bool
    {
        try {
            return ! Cache::add('monitoring:throttle:' . md5($key), 1, now()->addMinutes(max(1, (int) config('monitoring.failure_throttle_minutes', 15))));
        } catch (\Throwable) {
            return false;
        }
    }

    /** Event listeners must never break the console / queue / auth flow that fired them. */
    private static function guard(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
