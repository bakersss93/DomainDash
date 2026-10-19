<?php

namespace App\Console\Commands;

use App\Http\Controllers\Admin\ServicesController;
use App\Services\Sync\SyncDispatcher;
use App\Models\Domain;
use App\Services\AuditLogger;
use App\Services\Synergy\SynergyWholesaleClient;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RunSyncTaskCommand extends Command
{
    protected $signature = 'domaindash:sync-task {task : sync-domains|sync-hosting-services|sync-halo-assets|sync-itglue}';

    protected $description = 'Runs a configured DomainDash sync task for scheduler automation';

    public function handle(): int
    {
        $task = $this->argument('task');

        return match ($task) {
            'sync-domains' => $this->runSynergyDomainSync(),
            'sync-hosting-services' => $this->runHostingServiceSync(),
            'sync-halo-assets' => $this->runHaloAssetSync(),
            'sync-itglue' => $this->runItGlueSync(),
            default => self::INVALID,
        };
    }

    private function runSynergyDomainSync(): int
    {
        AuditLogger::logSystem('sync.started', 'Scheduled Synergy domain sync started.', [
            'service' => 'synergy',
            'function' => 'sync-domains',
        ]);

        $synergy = app(SynergyWholesaleClient::class);

        $page = 1;
        $limit = 500;
        $imported = 0;

        do {
            $response = $synergy->listDomains($page, $limit);

            if (($response['status'] ?? null) !== 'OK') {
                $error = $response['errorMessage'] ?? 'Unknown error from Synergy listDomains';
                $this->error('Synergy domain sync failed: ' . $error);
                Log::error('Scheduled Synergy domain sync failed', ['error' => $error, 'response' => $response]);
                AuditLogger::logSystem('sync.failed', 'Scheduled Synergy domain sync failed.', [
                    'service' => 'synergy',
                    'function' => 'sync-domains',
                ], [
                    'new_values' => ['error' => $error],
                ]);

                return self::FAILURE;
            }

            $list = $response['domainList'] ?? [];

            foreach ($list as $entry) {
                if (($entry->status ?? '') === 'ERR_DOMAIN_NOT_FOUND') {
                    continue;
                }

                $name = $entry->domainName ?? null;
                if (! $name) {
                    continue;
                }

                Domain::updateOrCreate(
                    ['name' => $name],
                    [
                        'status' => $entry->domainStatus ?? $entry->domain_status ?? null,
                        'expiry_date' => isset($entry->domain_expiry) ? substr($entry->domain_expiry, 0, 10) : null,
                        'name_servers' => $entry->nameServers ?? [],
                        'dns_config' => $entry->dnsConfig ?? null,
                        'auto_renew' => isset($entry->autoRenew)
                            ? in_array(strtolower((string) $entry->autoRenew), ['on', 'true', '1'], true)
                            : null,
                        'transfer_status' => $entry->transfer_status ?? null,
                    ]
                );

                $imported++;
            }

            $received = count($list);
            $page++;
            $hasMore = $received >= $limit;
        } while ($hasMore && $page < 1000);

        $this->info("Synergy domain sync complete. Imported/updated {$imported} domain records.");
        AuditLogger::logSystem('sync.completed', "Scheduled Synergy domain sync completed ({$imported} records).", [
            'service' => 'synergy',
            'function' => 'sync-domains',
        ], [
            'new_values' => ['imported' => $imported],
        ]);

        return self::SUCCESS;
    }

    private function runHostingServiceSync(): int
    {
        AuditLogger::logSystem('sync.started', 'Scheduled hosting service sync started.', [
            'service' => 'synergy',
            'function' => 'sync-hosting-services',
        ]);

        $controller = app(ServicesController::class);
        $synergy = app(SynergyWholesaleClient::class);

        $controller->sync(Request::create('/admin/services/hosting/sync', 'POST'), $synergy);
        $this->info('Hosting service sync triggered successfully.');
        AuditLogger::logSystem('sync.completed', 'Scheduled hosting service sync completed.', [
            'service' => 'synergy',
            'function' => 'sync-hosting-services',
        ]);

        return self::SUCCESS;
    }

    /**
     * Queue the Halo pipeline (clients -> assets -> changed domains) and return
     * immediately; a queue worker does the actual API work.
     */
    private function runHaloAssetSync(): int
    {
        app(SyncDispatcher::class)->scheduleHaloPipeline();
        $this->info('Halo sync queued (SyncHaloClientsJob, SyncHaloAssetsJob, SyncHaloDomainsJob). Run a queue worker to process it.');

        return self::SUCCESS;
    }

    /**
     * Queue the IT Glue pipeline (organisations -> changed domains).
     */
    private function runItGlueSync(): int
    {
        app(SyncDispatcher::class)->scheduleItGluePipeline();
        $this->info('IT Glue sync queued (SyncItGlueOrganizationsJob, SyncItGlueConfigurationsJob). Run a queue worker to process it.');

        return self::SUCCESS;
    }
}
