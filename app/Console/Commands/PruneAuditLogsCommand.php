<?php

namespace App\Console\Commands;

use App\Services\Monitoring\MonitoringPruner;
use Illuminate\Console\Command;

class PruneAuditLogsCommand extends Command
{
    protected $signature = 'domaindash:audit-prune';

    protected $description = 'Prunes activity, integration request and sync run history based on the per-category retention settings';

    public function handle(MonitoringPruner $pruner): int
    {
        $deleted = $pruner->prune();

        $this->info(sprintf(
            'Pruned %d activity, %d outbound, %d inbound and %d job row(s) using the configured retention.',
            $deleted['activity'], $deleted['outbound'], $deleted['inbound'], $deleted['jobs']
        ));

        return self::SUCCESS;
    }
}
