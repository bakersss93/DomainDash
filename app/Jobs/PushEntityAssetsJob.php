<?php

namespace App\Jobs;

use App\Models\SyncRun;
use App\Services\Assets\AssetEntity;
use App\Services\Halo\HaloAssetMapping;
use App\Services\Halo\HaloPsaClient;
use App\Services\Integrations\IntegrationApiException;
use App\Services\Integrations\IntegrationConfigurationException;
use App\Services\ItGlue\ItGlueAssetMapping;
use App\Services\ItGlue\ItGlueClient;
use App\Services\Sync\HaloEntityPusher;
use App\Services\Sync\ItGlueEntityPusher;
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
 * Pushes a chunk of internet services / hosting services / SSL certificates to HaloPSA or IT Glue as assets. Same
 * contract as PushDomainsToHaloJob / PushDomainsToItGlueJob: per-item failure isolation (the error is stored on the
 * record's *_sync_error column and counted in the SyncRun), capped transient-error rounds recorded on the run,
 * auth / configuration errors fail the rest fast, and a time budget that hands the remainder back to the queue.
 * The provider's rate limiter (halo-api / itglue-api) throttles the chunk.
 */
class PushEntityAssetsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 0;

    public int $maxExceptions = 1;

    public bool $failOnTimeout = true;

    public int $timeout = 180;

    public const MAX_ROUNDS = 4;

    public ?int $budgetSeconds = null;

    public function __construct(
        public string $provider,
        public string $entity,
        public array $ids,
        public ?int $runId = null,
        public bool $force = false,
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
            (new WithoutOverlapping("{$this->provider}-push-{$this->entity}-" . md5(json_encode($this->ids))))->releaseAfter(60)->expireAfter($this->timeout + 60),
            new RateLimited($this->provider === 'halo' ? 'halo-api' : 'itglue-api'),
        ];
    }

    private function label(): string
    {
        return $this->provider === 'halo' ? 'HaloPSA' : 'IT Glue';
    }

    public function handle(): void
    {
        $started = microtime(true);
        $budget = $this->budgetSeconds ?? (int) config('integrations.sync.push_budget_seconds', 120);
        $run = $this->runId ? SyncRun::find($this->runId) : null;
        $remaining = array_values($this->ids);
        $halo = $this->provider === 'halo';

        if ($halo ? ! HaloPsaClient::isConfigured() : ! ItGlueClient::isConfigured()) {
            $this->failAll($remaining, $run, $halo ? (HaloPsaClient::configurationProblem() ?? 'HaloPSA is not configured.') : 'IT Glue is not configured. Set the API key in Settings → IT Glue.');

            return;
        }

        $mapping = $halo ? HaloAssetMapping::forEntity($this->entity) : ItGlueAssetMapping::forEntity($this->entity);
        if (! $mapping->enabled || ! $mapping->typeId) {
            $this->failAll($remaining, $run, AssetEntity::label($this->entity) . ' asset sync is turned off or has no asset type in Settings → ' . $this->label() . ' → Assets.');

            return;
        }

        try {
            $client = $halo ? app(HaloPsaClient::class) : app(ItGlueClient::class);
            $pusher = $halo ? app(HaloEntityPusher::class) : app(ItGlueEntityPusher::class);
        } catch (\Throwable $e) {
            $this->failAll($remaining, $run, SyncErrorSanitizer::describe($e));

            return;
        }

        $model = AssetEntity::model($this->entity);

        while ($remaining !== []) {
            if ((microtime(true) - $started) >= $budget) {
                static::dispatch($this->provider, $this->entity, $remaining, $this->runId, $this->force, $this->round);
                $run?->noteError('Partly processed; ' . count($remaining) . ' item(s) continue in the background queue.');

                return;
            }

            $id = $remaining[0];
            $record = $model::with(AssetEntity::with($this->entity))->find($id);

            try {
                if (! $record) {
                    throw new SyncItemFailed(ucfirst(AssetEntity::singular($this->entity)) . ' not found');
                }

                $outcome = $pusher->push($this->entity, $record, $client, $mapping, $this->force);
                $run?->advance(true, $outcome === 'skipped');
            } catch (IntegrationApiException $e) {
                if ($e->retryable) {
                    $this->requeue($remaining, $run, $e);

                    return;
                }
                if ($e->isAuthFailure()) {
                    $this->failAll($remaining, $run, SyncErrorSanitizer::describe($e) . ' Check the ' . $this->label() . ' credentials in Settings.');

                    return;
                }
                $this->recordFailure($record, SyncErrorSanitizer::describe($e));
                $run?->advance(false);
            } catch (IntegrationConfigurationException $e) {
                $this->failAll($remaining, $run, SyncErrorSanitizer::describe($e));

                return;
            } catch (\Throwable $e) {
                if (! $e instanceof SyncItemFailed) {
                    Log::error($this->label() . ' asset push failed', ['entity' => $this->entity, 'id' => $id, 'error' => SyncErrorSanitizer::describe($e)]);
                }
                $this->recordFailure($record, SyncErrorSanitizer::describe($e));
                $run?->advance(false);
            }

            array_shift($remaining);
        }
    }

    public function failed(\Throwable $e): void
    {
        $run = $this->runId ? SyncRun::find($this->runId) : null;

        $name = class_basename($e);
        $message = str_contains($name, 'Timeout')
            ? "Job timed out after {$this->timeout}s and was stopped."
            : (str_contains($name, 'MaxAttempts') ? 'Job exceeded its retry limit.' : SyncErrorSanitizer::describe($e));

        $ids = $this->ids;
        if ($run) {
            $cols = AssetEntity::columns($this->entity, $this->provider);
            $room = max(0, (int) $run->total - ((int) $run->done + (int) $run->failed + (int) $run->skipped));
            $ids = AssetEntity::model($this->entity)::whereIn('id', $this->ids)
                ->whereNull($cols['error'])
                ->where(fn ($q) => $q->whereNull($cols['synced'])->orWhere($cols['synced'], '<', $run->created_at))
                ->pluck('id')->take($room)->all();
        }

        $this->failAll($ids, $run, $message);
    }

    private function requeue(array $remaining, ?SyncRun $run, IntegrationApiException $e): void
    {
        $message = SyncErrorSanitizer::describe($e);

        if ($this->round >= self::MAX_ROUNDS) {
            $this->failAll($remaining, $run, 'Gave up after repeated ' . $this->label() . ' errors: ' . $message);

            return;
        }

        $delay = max(15, min($e->retryAfter ?? (30 * (2 ** ($this->round - 1))), 300));
        $run?->recordRetry($message . ' [round ' . $this->round . '/' . self::MAX_ROUNDS . ']', $delay);

        static::dispatch($this->provider, $this->entity, $remaining, $this->runId, $this->force, $this->round + 1)
            ->delay(now()->addSeconds($delay));
    }

    private function failAll(array $ids, ?SyncRun $run, string $message): void
    {
        if ($run && ! $run->isFinished()) {
            $run->noteError($message);
        }

        $model = AssetEntity::model($this->entity);
        foreach ($ids as $id) {
            $this->recordFailure($model::find($id), $message);
            $run?->advance(false);
        }

        if ($run && $ids === []) {
            $run->refresh();
            if (! $run->isFinished()) {
                $run->fail($message);
            }
        }
    }

    private function recordFailure($record, string $message): void
    {
        if (! $record) {
            return;
        }
        $cols = AssetEntity::columns($this->entity, $this->provider);
        $record->forceFill([$cols['error'] => mb_substr($message, 0, 1000)])->save();
    }
}
