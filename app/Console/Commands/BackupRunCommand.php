<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupException;
use App\Services\Backup\BackupService;
use Illuminate\Console\Command;

class BackupRunCommand extends Command
{
    protected $signature = 'domaindash:backup-run';
    protected $description = 'Dump the database, encrypt it (AES-256 zip), upload to SFTP (host key pinned) and apply retention';

    public function handle(BackupService $backups): int
    {
        if (! BackupService::isConfigured()) {
            $this->warn('Backup settings not configured.');

            return self::SUCCESS;
        }

        try {
            $result = $backups->run();
        } catch (BackupException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Backup completed: ' . $result['file']);

        return self::SUCCESS;
    }
}
