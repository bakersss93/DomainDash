<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\AuditLogger;
use Illuminate\Console\Command;

class PruneAuditLogsCommand extends Command
{
    protected $signature = 'domaindash:audit-prune';

    protected $description = 'Prunes audit logs based on configured retention settings';

    public function handle(): int
    {
        $auditSettings = Setting::get('audit', ['retention_days' => 90]);
        $retentionDays = (int) ($auditSettings['retention_days'] ?? 90);

        $deleted = AuditLogger::pruneOlderThanDays($retentionDays);
        $this->info("Pruned {$deleted} audit log row(s) using {$retentionDays}-day retention.");

        return self::SUCCESS;
    }
}
