<?php

namespace App\Observers;

use App\Models\InternetService;
use App\Services\Sync\SyncDispatcher;
use Illuminate\Support\Facades\Log;

/**
 * Keeps Halo billing in step: a new service with a client, a client assigned or changed, a plan or price change, or a
 * status change queues a debounced PushInternetServicesToHaloJob (see SyncDispatcher::queueInternetServiceBilling).
 * Writes of the halo_* bookkeeping columns themselves never trigger it. Never breaks the save.
 */
class InternetServiceObserver
{
    /** Columns whose change means Halo has to hear about it. */
    public const WATCHED = ['client_id', 'vocus_plan_id', 'service_status', 'sell_price', 'cost_price', 'address_long', 'username'];

    public function saved(InternetService $service): void
    {
        if (! $service->client_id) {
            return;
        }

        $relevant = $service->wasRecentlyCreated || $service->wasChanged(self::WATCHED);
        if (! $relevant) {
            return;
        }

        try {
            app(SyncDispatcher::class)->queueInternetServiceBilling($service);
        } catch (\Throwable $e) {
            Log::warning('Could not queue Halo billing sync', ['service_id' => $service->id, 'error' => $e->getMessage()]);
        }
    }
}
