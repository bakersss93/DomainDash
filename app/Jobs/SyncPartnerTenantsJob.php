<?php

namespace App\Jobs;

use App\Jobs\Concerns\TracksSyncRun;
use App\Models\SyncRun;
use App\Services\Microsoft\Partner\PartnerGraphException;
use App\Services\Microsoft\Partner\PartnerSettings;
use App\Services\Microsoft\Partner\PartnerTenantSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Mirrors the Microsoft partner's customer tenants (Settings > Microsoft Partner). */
class SyncPartnerTenantsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TracksSyncRun;

    public const PROVIDER = 'microsoft_partner';
    public const SCOPE = 'tenants';

    public int $timeout = 240;

    public function __construct(?int $runId = null, private readonly bool $automated = false)
    {
        $this->runId = $runId;
        $this->onQueue(config('integrations.sync.queue'));
    }

    public function uniqueId(): string
    {
        return 'microsoft-partner-tenants';
    }

    public function middleware(): array
    {
        return $this->syncMiddleware(null);
    }

    public function handle(PartnerTenantSync $sync): void
    {
        $run = $this->syncRun(self::PROVIDER, self::SCOPE)->markRunning();

        if (! PartnerSettings::configured()) {
            $run->skip('The Microsoft partner connection is not configured. Set it up in Settings > Microsoft Partner.');

            return;
        }

        try {
            $stats = $sync->run($this->automated);
            $run->forceFill(['total' => $stats['total'], 'done' => $stats['total']])->save();
            $run->finish();
        } catch (PartnerGraphException $e) {
            // Credential / permission / Graph problems are not retried: the message is already operator-safe.
            $run->fail($e->getMessage());
        } catch (\Throwable $e) {
            $this->handleFailure($run, $e);
        }
    }
}
