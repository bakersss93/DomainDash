<?php

namespace App\Services\Notifications\Providers;

use App\Services\Notifications\AlertProvider;
use App\Services\Sync\QueueHealth;

class QueueWorkerAlerts implements AlertProvider
{
    public function alerts(): array
    {
        $status = app(QueueHealth::class)->status();
        if (! $status['stale']) {
            return [];
        }

        return [[
            'key' => 'queue-worker',
            'severity' => 'warning',
            'title' => 'Queue worker not running',
            'text' => (string) ($status['message'] ?? 'No queue worker is processing jobs.'),
            'url' => route('admin.settings.area', 'sync'),
            'at' => $status['heartbeat_at'],
        ]];
    }
}
