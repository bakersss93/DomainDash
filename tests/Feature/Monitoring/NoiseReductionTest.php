<?php

namespace Tests\Feature\Monitoring;

use App\Jobs\QueueHeartbeatJob;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\MonitoringHelpers;
use Tests\TestCase;

class NoiseReductionTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    private function job(string $name): Job
    {
        $job = \Mockery::mock(Job::class);
        $job->shouldReceive('resolveName')->andReturn($name);
        $job->shouldReceive('getQueue')->andReturn('default');
        $job->shouldReceive('payload')->andReturn([]); // read by Laravel's log-context listener on JobProcessing

        return $job;
    }

    private function finished(string $command, int $exit): void
    {
        $input = new ArrayInput([]);
        $output = new BufferedOutput();
        Event::dispatch(new CommandStarting($command, $input, $output));
        Event::dispatch(new CommandFinished($command, $input, $output, $exit));
    }

    public function test_get_page_views_are_not_recorded(): void
    {
        Route::middleware(['web', 'auth'])->get('/_noise-page', fn () => 'ok');
        $this->actingAs(User::factory()->create())->get('/_noise-page')->assertOk();

        $this->assertSame(0, AuditLog::count());
    }

    public function test_queue_heartbeat_and_routine_job_events_are_not_recorded(): void
    {
        $job = $this->job(QueueHeartbeatJob::class);
        Event::dispatch(new JobProcessing('database', $job));
        Event::dispatch(new JobProcessed('database', $job));
        Event::dispatch(new JobFailed('database', $job, new \RuntimeException('boom')));

        $other = $this->job('App\\Jobs\\SyncHaloClientsJob');
        Event::dispatch(new JobProcessing('database', $other));
        Event::dispatch(new JobProcessed('database', $other));

        $this->assertSame(0, AuditLog::count());
    }

    public function test_failed_job_is_recorded_once_per_throttle_window(): void
    {
        $job = $this->job('App\\Jobs\\SyncHaloClientsJob');
        Event::dispatch(new JobFailed('database', $job, new \RuntimeException('Halo is down')));
        Event::dispatch(new JobFailed('database', $job, new \RuntimeException('Halo is down')));

        $this->assertSame(1, AuditLog::where('action', 'queue.job_failed')->count());
        $this->assertStringContainsString('SyncHaloClientsJob', AuditLog::first()->description);
    }

    public function test_routine_console_commands_are_not_recorded_but_failures_are(): void
    {
        $this->finished('domaindash:certctl', 0);
        $this->finished('domaindash:notify-expiring', 0);
        $this->finished('migrate', 0);
        $this->assertSame(0, AuditLog::count());

        $this->finished('domaindash:certctl', 1);   // silent daemon: never recorded
        $this->assertSame(0, AuditLog::count());

        $this->finished('domaindash:notify-expiring', 1);
        $this->assertSame(1, AuditLog::where('action', 'console.command_failed')->count());
    }

    public function test_scheduler_finished_is_dropped_unless_slow(): void
    {
        $task = app(Schedule::class)->call(fn () => null)->description('Command: example');

        Event::dispatch(new ScheduledTaskFinished($task, 1.5));
        $this->assertSame(0, AuditLog::count());

        Event::dispatch(new ScheduledTaskFinished($task, 400.0));
        $this->assertSame(1, AuditLog::where('action', 'scheduler.task_slow')->count());
    }

    public function test_verbose_setting_brings_the_debug_events_back(): void
    {
        $this->monitoring(['verbose' => true]);

        Route::middleware(['web', 'auth'])->get('/_noise-verbose', fn () => 'ok');
        $this->actingAs(User::factory()->create())->get('/_noise-verbose')->assertOk();
        Event::dispatch(new JobProcessed('database', $this->job('App\\Jobs\\SyncHaloClientsJob')));

        $this->assertSame(1, AuditLog::where('action', 'http.get')->count());
        $this->assertSame(1, AuditLog::where('action', 'queue.job_finished')->count());
    }

    public function test_config_default_for_verbose_is_off(): void
    {
        $this->assertFalse(config('monitoring.verbose'));
    }
}
