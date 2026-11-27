<?php

namespace App\Jobs;

use App\Models\InternetService;
use App\Models\SyncRun;
use App\Services\Halo\HaloBillingSettings;
use App\Services\Halo\HaloPsaClient;
use App\Services\Integrations\IntegrationApiException;
use App\Services\Integrations\IntegrationConfigurationException;
use App\Services\Sync\HaloSubscriptionPusher;
use App\Services\Sync\SyncItemFailed;
use App\Support\SyncErrorSanitizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * Bills Vocus internet services in HaloPSA (subscription + recurring invoice line, see HaloSubscriptionPusher).
 *
 * One SyncRun (provider "halo", scope "subscriptions") shows live progress in DDSync when started from the UI; the
 * automatic, debounced triggers pass no run. Every service is isolated: a failure is written to the service
 * (halo_sync_error) and counted on the run. Transient Halo errors hand the unprocessed remainder back to the queue
 * (capped rounds, like PushDomainsToHaloJob); rejected credentials fail the whole remainder at once.
 */
class PushInternetServicesToHaloJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 0;

    public int $maxExceptions = 1;

    public bool $failOnTimeout = true;

    public int $timeout = 180;

    public const MAX_ROUNDS = 4;

    /**
     * @param int[]|null $serviceIds null = every service that has a client
     */
    public function __construct(
        public ?array $serviceIds = null,
        public bool $force = false,
        public ?int $runId = null,
        public int $round = 1,
    ) {
        $this->timeout = (int) config('integrations.sync.timeouts.push', 180);
        $this->onQueue(config('integrations.sync.queue'));
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes((int) config('integrations.sync.retry_minutes', 30));
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('halo-subscriptions-' . md5(json_encode($this->serviceIds))))->releaseAfter(30)->expireAfter($this->timeout + 60),
            new RateLimited('halo-api'),
        ];
    }

    public function handle(HaloSubscriptionPusher $pusher): void
    {
        $run = $this->runId ? SyncRun::find($this->runId) : null;

        $ids = $this->serviceIds ?? InternetService::query()->whereNotNull('client_id')->orderBy('id')->pluck('id')->all();
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($run && $this->round === 1) {
            $run->markRunning(count($ids));
        }
        if ($ids === []) {
            $run?->finish();

            return;
        }

        if (! HaloPsaClient::isConfigured()) {
            $this->failAll($ids, $run, HaloPsaClient::configurationProblem() ?? 'HaloPSA is not configured.');

            return;
        }

        $cfg = HaloBillingSettings::fromSettings();
        if (! $cfg->enabled()) {
            $message = 'Billing sync is switched off in Settings → HaloPSA → Billing.';
            if ($run) {
                $run->skip($message);
            }

            return;
        }

        $halo = app(HaloPsaClient::class);
        $deadline = microtime(true) + (int) config('integrations.sync.push_budget_seconds', 120);
        $remaining = $ids;

        while ($remaining !== []) {
            if (microtime(true) >= $deadline) {
                static::dispatch($remaining, $this->force, $this->runId, $this->round);
                $run?->noteError('Partly processed; ' . count($remaining) . ' service(s) continue in the background queue.');

                return;
            }

            $id = $remaining[0];
            $service = InternetService::with(['client', 'vocusPlan'])->find($id);

            try {
                if (! $service) {
                    throw new SyncItemFailed('Service not found.');
                }
                $outcome = $pusher->push($service, $halo, $cfg, $this->force);
                $run?->advance(true, $outcome === 'skipped');
            } catch (IntegrationApiException $e) {
                if ($e->retryable) {
                    $this->requeue($remaining, $run, $e);

                    return;
                }
                if ($e->isAuthFailure()) {
                    $this->failAll($remaining, $run, SyncErrorSanitizer::describe($e) . ' Check the HaloPSA credentials in Settings.');

                    return;
                }
                $this->recordFailure($service, SyncErrorSanitizer::describe($e));
                $run?->advance(false);
            } catch (IntegrationConfigurationException $e) {
                $this->failAll($remaining, $run, SyncErrorSanitizer::describe($e));

                return;
            } catch (\Throwable $e) {
                if (! $e instanceof SyncItemFailed) {
                    Log::error('Halo subscription push failed', ['service_id' => $id, 'error' => SyncErrorSanitizer::describe($e)]);
                }
                $this->recordFailure($service, SyncErrorSanitizer::describe($e));
                $run?->advance(false);
            }

            array_shift($remaining);
        }

        // Runs with a total are closed by advance(); make sure an all-skipped / empty remainder also closes.
        if ($run) {
            $run->refresh();
            if (! $run->isFinished() && $run->total > 0 && ($run->done + $run->failed + $run->skipped) >= $run->total) {
                $run->finish();
            }
        }
    }

    public function failed(\Throwable $e): void
    {
        $run = $this->runId ? SyncRun::find($this->runId) : null;
        if (! $run || $run->isFinished()) {
            return;
        }

        $name = class_basename($e);
        $run->fail(str_contains($name, 'Timeout')
            ? "Job timed out after {$this->timeout}s and was stopped."
            : (str_contains($name, 'MaxAttempts') ? 'Job exceeded its retry limit.' : SyncErrorSanitizer::describe($e)));
    }

    private function requeue(array $remaining, ?SyncRun $run, IntegrationApiException $e): void
    {
        $message = SyncErrorSanitizer::describe($e);

        if ($this->round >= self::MAX_ROUNDS) {
            $this->failAll($remaining, $run, 'Gave up after repeated HaloPSA errors: ' . $message);

            return;
        }

        $delay = max(15, min($e->retryAfter ?? (30 * (2 ** ($this->round - 1))), 300));
        $run?->recordRetry($message . ' [round ' . $this->round . '/' . self::MAX_ROUNDS . ']', $delay);

        static::dispatch($remaining, $this->force, $this->runId, $this->round + 1)->delay(now()->addSeconds($delay));
    }

    private function failAll(array $ids, ?SyncRun $run, string $message): void
    {
        if ($run && ! $run->isFinished()) {
            $run->noteError($message);
        }

        foreach ($ids as $id) {
            $this->recordFailure(InternetService::find($id), $message);
            $run?->advance(false);
        }

        if ($run && $ids === []) {
            $run->refresh();
            if (! $run->isFinished()) {
                $run->fail($message);
            }
        }
    }

    private function recordFailure(?InternetService $service, string $message): void
    {
        $service?->forceFill(['halo_sync_error' => mb_substr($message, 0, 1000)])->save();
    }
}
