<?php

namespace App\Jobs;

use App\Jobs\Concerns\TracksSyncRun;
use App\Models\InternetService;
use App\Models\SyncRun;
use App\Services\AuditLogger;
use App\Services\Vocus\VocusWsmClient;
use App\Services\Vocus\VocusWsmException;
use App\Support\SyncErrorSanitizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Refreshes internet services from Vocus WSM (FIBRE / Get / SERVICE), one call
 * per service, in the background. Replaces the old in-request loops (sync,
 * "Import Service IDs" and the API sync) which hit php-fpm timeouts and kept
 * hammering Vocus after an account-level failure.
 *
 *  - progress is a SyncRun (provider "vocus", scope "services") shown live by DDSync
 *  - every call goes through the client's shared VocusRateLimiter (4 per 10 s,
 *    under the documented 5 per 10 s), so a run of N services takes about 2.5 s each
 *  - the job works on a time budget: when it is used up, the remaining ids are
 *    handed to a follow-up job of the same run, so no single job can hit the
 *    queue timeout
 *  - account-level errors (WSM-4005 after relogin, 4010, 4020, 4050, missing
 *    Access Key / certificate / login rejected) abort the WHOLE run at once with
 *    one clear message instead of failing every remaining row
 *  - with $create (import by ids) a local row is created only after Vocus has
 *    returned the service, so a failed import leaves no blank rows behind
 */
class SyncVocusServicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TracksSyncRun;

    public int $timeout = 240;

    /**
     * @param string[]|null $vocusIds Vocus ServiceIDs to refresh; null = every locally known service
     */
    public function __construct(
        public ?array $vocusIds = null,
        public bool $create = false,
        ?int $runId = null,
    ) {
        $this->runId = $runId;
        $this->onQueue(config('integrations.sync.queue'));
    }

    public function uniqueId(): string
    {
        return 'vocus-services';
    }

    public function middleware(): array
    {
        // No Laravel limiter: the Vocus client paces every call itself (shared cache sliding window).
        return $this->syncMiddleware(null);
    }

    /** Failures shown in the live panel (id/name/error), kept in the cache per run. */
    public static function failuresFor(int $runId): array
    {
        return (array) Cache::get(self::failuresKey($runId), []);
    }

    private static function failuresKey(int $runId): string
    {
        return 'vocus-run-failures:' . $runId;
    }

    public function handle(): void
    {
        $run = $this->syncRun('vocus', 'services')->markRunning();

        if (($problem = VocusWsmClient::configurationProblem()) !== null) {
            $run->skip($problem);

            return;
        }

        try {
            $ids = $this->vocusIds ?? InternetService::query()
                ->whereNotNull('vocus_service_id')
                ->orderBy('id')
                ->pluck('vocus_service_id')
                ->all();
            $ids = array_values(array_unique(array_map('strval', $ids)));

            // First job of the run sets the total; follow-up jobs keep it.
            if ((int) $run->total === 0) {
                $run->setTotal(count($ids));
            }

            if ($ids === []) {
                $run->finish();

                return;
            }

            $this->process($run, $ids);
        } catch (\Throwable $e) {
            $this->handleFailure($run, $e);
        }
    }

    /** @param string[] $ids */
    private function process(SyncRun $run, array $ids): void
    {
        $client = app(VocusWsmClient::class);
        $deadline = microtime(true) + max(10, (int) config('services.vocus.sync_budget_seconds', 150));

        foreach ($ids as $i => $serviceId) {
            if (microtime(true) > $deadline) {
                // Budget used up: continue the same run in a fresh job.
                static::dispatch(array_slice($ids, $i), $this->create, $run->id);
                $run->heartbeat();

                return;
            }

            try {
                $outcome = $this->syncOne($client, $serviceId);
                $run->advance(true, $outcome === 'unchanged');
            } catch (VocusWsmException $e) {
                if ($e->isAccountLevel()) {
                    $this->abort($run, $e, count($ids) - $i - 1);

                    return;
                }

                $this->recordItemFailure($run, $serviceId, $e);
            } catch (\Throwable $e) {
                $this->recordItemFailure($run, $serviceId, $e);
            }

            $run->heartbeat();
        }

        $run->refresh();
        if (! $run->isFinished()) {
            $run->finish();
        }

        $this->audit($run);
    }

    /** @return 'updated'|'unchanged' */
    private function syncOne(VocusWsmClient $client, string $serviceId): string
    {
        $service = InternetService::where('vocus_service_id', $serviceId)->first();
        if (! $service && ! $this->create) {
            return 'unchanged'; // row removed since the run was queued
        }

        $params = $this->fetch($client, $serviceId)['params'] ?? [];
        if (empty($params)) {
            return 'unchanged';
        }

        $service ??= new InternetService(['vocus_service_id' => $serviceId]);
        $service->applyLiveParams($params)->save();

        return 'updated';
    }

    /** One call, plus one retry when the local limiter could not find a slot in time. */
    private function fetch(VocusWsmClient $client, string $serviceId): array
    {
        try {
            return $client->getService($serviceId);
        } catch (VocusWsmException $e) {
            if ($e->errorCode !== 'LOCAL-RATE-LIMIT') {
                throw $e;
            }

            sleep(10);

            return $client->getService($serviceId);
        }
    }

    private function recordItemFailure(SyncRun $run, string $serviceId, \Throwable $e): void
    {
        $message = SyncErrorSanitizer::describe($e);

        Log::warning('Vocus service refresh failed', [
            'run' => $run->id,
            'service_id' => $serviceId,
            'error_code' => $e instanceof VocusWsmException ? $e->errorCode : null,
            'message' => $message,
        ]);

        $key = self::failuresKey($run->id);
        $failures = (array) Cache::get($key, []);
        if (count($failures) < 50) {
            $failures[] = ['id' => count($failures) + 1, 'name' => $serviceId, 'error' => $message];
            Cache::put($key, $failures, 3600);
        }

        $run->advance(false);
    }

    /** Stop the whole run on an account-level error: repeating the call per row cannot succeed. */
    private function abort(SyncRun $run, VocusWsmException $e, int $notProcessed): void
    {
        $code = $e->errorCode ? " ({$e->errorCode})" : '';
        $message = "Stopped at the first account-level error{$code}: " . SyncErrorSanitizer::describe($e)
            . " {$notProcessed} further service(s) were not contacted.";

        if ($e->isConfigurationProblem()) {
            $message .= ' Check the Vocus settings.';
        }

        Log::error('Vocus sync aborted on an account-level error', [
            'run' => $run->id,
            'error_code' => $e->errorCode,
            'not_processed' => $notProcessed,
        ]);

        $run->refresh();
        // Items already done stay counted (partial); with none done the run is failed.
        $run->finish($message);

        $this->audit($run);
    }

    private function audit(SyncRun $run): void
    {
        try {
            $run->refresh();
            AuditLogger::logSystem('sync.completed', "Vocus internet service sync finished ({$run->status}: {$run->done} refreshed, {$run->failed} failed, {$run->skipped} unchanged).", [
                'service' => 'vocus',
                'function' => $this->create ? 'internet-import' : 'internet-sync',
            ], ['new_values' => ['run_id' => $run->id, 'status' => $run->status, 'refreshed' => $run->done, 'failed' => $run->failed, 'unchanged' => $run->skipped]]);
        } catch (\Throwable) {
            // audit must never fail the run
        }
    }
}
