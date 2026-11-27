<?php

namespace App\Services\Notifications\Providers;

use App\Services\Notifications\AlertProvider;

class PendingMigrationAlerts implements AlertProvider
{
    public function alerts(): array
    {
        try {
            $migrator = app('migrator');
            $repository = $migrator->getRepository();
            if (! $repository->repositoryExists()) {
                return [];
            }
            $files = array_keys($migrator->getMigrationFiles([database_path('migrations')]));
            $pending = count(array_diff($files, $repository->getRan()));
        } catch (\Throwable) {
            return [];
        }

        if ($pending === 0) {
            return [];
        }

        return [[
            'key' => 'pending-migrations',
            'severity' => 'warning',
            'title' => 'Database migrations pending',
            'text' => "{$pending} migration(s) have not been run. Run php artisan migrate --force.",
            'url' => null,
            'at' => null,
        ]];
    }
}
