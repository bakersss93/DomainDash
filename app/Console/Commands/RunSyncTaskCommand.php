<?php

namespace App\Console\Commands;

use App\Http\Controllers\Admin\ServicesController;
use App\Http\Controllers\Admin\SyncController;
use App\Models\Client;
use App\Models\Domain;
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

        return self::SUCCESS;
    }

    private function runHaloDomainSync(): int
    {
        $domainIds = Domain::whereNotNull('client_id')->pluck('id')->all();

        if (empty($domainIds)) {
            $this->info('No client-linked domains found for Halo domain sync.');
            return self::SUCCESS;
        }

        $controller = app(SyncController::class);
        $response = $controller->syncHaloDomains(new Request(['domain_ids' => $domainIds]));
        $payload = $response->getData(true);

        if (!empty($payload['error'])) {
            $this->error('Halo domain sync failed: ' . $payload['error']);
            Log::error('Scheduled halo domain sync failed', $payload);
            return self::FAILURE;
        }

        $this->info('Halo domain sync complete. Synced: ' . ($payload['synced_count'] ?? 0));

        return self::SUCCESS;
    }

    private function runHostingServiceSync(): int
    {
        $controller = app(ServicesController::class);
        $synergy = app(SynergyWholesaleClient::class);

        $controller->sync(Request::create('/admin/services/hosting/sync', 'POST'), $synergy);
        $this->info('Hosting service sync triggered successfully.');

        return self::SUCCESS;
    }

    private function runHaloAssetSync(): int
    {
        $mappings = Client::whereNotNull('halopsa_reference')
            ->get(['id', 'halopsa_reference'])
            ->map(fn ($client) => [
                'halo_id' => (string) $client->halopsa_reference,
                'dash_client_id' => $client->id,
            ])
            ->all();

        $controller = app(SyncController::class);

        if (!empty($mappings)) {
            $clientSyncResponse = $controller->syncHaloClients(new Request(['clients' => $mappings]));
            $clientSyncPayload = $clientSyncResponse->getData(true);

            if (!empty($clientSyncPayload['error'])) {
                $this->error('Halo client sync failed: ' . $clientSyncPayload['error']);
                Log::error('Scheduled halo client sync failed', $clientSyncPayload);
                return self::FAILURE;
            }
        }

        return $this->runHaloDomainSync();
    }

    private function runItGlueSync(): int
    {
        $items = Domain::query()
            ->whereHas('client', function ($query) {
                $query->whereNotNull('itglue_org_id');
            })
            ->get(['id'])
            ->map(fn ($domain) => [
                'id' => $domain->id,
                'type' => 'domain',
            ])
            ->all();

        if (empty($items)) {
            $this->info('No IT Glue-linked domains found for sync.');
            return self::SUCCESS;
        }

        $controller = app(SyncController::class);
        $response = $controller->syncItGlueConfigurations(new Request(['items' => $items]));
        $payload = $response->getData(true);

        if (!empty($payload['error'])) {
            $this->error('IT Glue sync failed: ' . $payload['error']);
            Log::error('Scheduled IT Glue sync failed', $payload);
            return self::FAILURE;
        }

        $this->info('IT Glue configuration sync complete. Synced: ' . ($payload['synced_count'] ?? 0));

        return self::SUCCESS;
    }
}
