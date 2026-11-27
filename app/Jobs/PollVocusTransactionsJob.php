<?php

namespace App\Jobs;

use App\Models\InternetService;
use App\Services\Vocus\VocusTransactionPoller;
use App\Services\Vocus\VocusWsmClient;
use App\Services\Vocus\VocusWsmException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled follow-up of pending Vocus transactions (QUEUED / PROCESSING): one TCAS Get RESPONSE per service,
 * skipping services checked within the poll cool-down, so Vocus's polling limits are respected (Examples 4.1.6).
 * Stops at the first account-level fault (bad key, certificate, ban) instead of repeating it for every service.
 */
class PollVocusTransactionsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;
    public int $timeout = 300;
    public int $uniqueFor = 600;

    /** Most services polled in one run. */
    public const BATCH = 25;

    public function handle(): void
    {
        if (VocusWsmClient::configurationProblem()) {
            return;
        }

        $poller = app(VocusTransactionPoller::class);
        $services = InternetService::query()
            ->whereNotNull('last_transaction_id')
            ->whereIn('last_transaction_state', ['QUEUED', 'PROCESSING'])
            ->orderByRaw('last_polled_at is not null')->orderBy('last_polled_at')
            ->limit(self::BATCH * 2)->get()
            ->reject(fn (InternetService $s) => $s->isPollCoolingDown())
            ->take(self::BATCH);

        foreach ($services as $service) {
            try {
                $poller->poll($service);
            } catch (VocusWsmException $e) {
                if ($e->isAccountLevel()) {
                    Log::warning('Scheduled Vocus poll stopped: account-level fault', ['code' => $e->errorCode]);

                    return;
                }
                if (! VocusTransactionPoller::isExpired($e)) {
                    Log::warning('Scheduled Vocus poll failed', ['service' => $service->vocus_service_id, 'code' => $e->errorCode]);
                }
            } catch (\Throwable $e) {
                Log::warning('Scheduled Vocus poll failed', ['service' => $service->vocus_service_id, 'message' => $e->getMessage()]);
            }
        }
    }
}
