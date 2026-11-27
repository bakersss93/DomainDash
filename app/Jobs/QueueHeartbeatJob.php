<?php

namespace App\Jobs;

use App\Services\Sync\QueueHealth;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Dispatched every minute by the scheduler. When it executes, a queue worker is
 * alive on this queue; the UI warns when the stamp goes stale.
 */
class QueueHeartbeatJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 20;

    /** At most one pending heartbeat (a dead worker must not pile up one per minute). */
    public int $uniqueFor = 300;

    public function __construct()
    {
        $this->onQueue(config('integrations.sync.queue'));
    }

    public function handle(): void
    {
        QueueHealth::stamp();
    }
}
