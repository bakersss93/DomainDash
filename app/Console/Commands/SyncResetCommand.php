<?php

namespace App\Console\Commands;

use App\Models\SyncRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SyncResetCommand extends Command
{
    protected $signature = 'domaindash:sync-reset
        {--all : Fail EVERY queued/running run, not only stale ones}
        {--clear-locks : Delete leftover unique-job / overlap locks from the database cache}
        {--flush-failed= : Delete failed_jobs rows of this job class (e.g. "App\Jobs\SyncSynergyDomainsJob")}
        {--dry-run : Show what would change without changing anything}';

    protected $description = 'Recover stuck HaloPSA / IT Glue sync state: fail stale runs, clear orphaned locks, flush old failed jobs';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        // 1. Runs
        $query = SyncRun::query()->whereIn('status', [SyncRun::QUEUED, SyncRun::RUNNING])->orderBy('id');
        $failed = 0;
        foreach ($query->get() as $run) {
            $reason = $this->option('all') ? 'Manually reset (domaindash:sync-reset).' : $run->staleReason();
            if ($reason === null) {
                $this->line("run #{$run->id} {$run->provider}/{$run->scope} {$run->status}: alive, left alone");
                continue;
            }
            $this->line("run #{$run->id} {$run->provider}/{$run->scope} {$run->status}: " . ($dry ? 'would fail' : 'failed') . " ({$reason})");
            if (! $dry) {
                $run->fail($reason);
            }
            $failed++;
        }
        $this->info(($dry ? 'Would fail ' : 'Failed ') . "{$failed} run(s).");

        // 2. Locks
        if ($this->option('clear-locks')) {
            if (config('cache.default') === 'database' && Schema::hasTable(config('cache.stores.database.lock_table', 'cache_locks'))) {
                $table = config('cache.stores.database.lock_table', 'cache_locks');
                $q = DB::table($table)->where(fn ($w) => $w->where('key', 'like', '%laravel_unique_job%')->orWhere('key', 'like', '%laravel-queue-overlap%'));
                $n = (clone $q)->count();
                if (! $dry) {
                    $q->delete();
                }
                $this->info(($dry ? 'Would delete ' : 'Deleted ') . "{$n} lock(s).");
            } else {
                $this->warn('Lock clearing is only automated for CACHE_STORE=database. Run `php artisan cache:clear` for other stores (this also drops cached Halo tokens, which is harmless).');
            }
        }

        // 3. Failed jobs of one class
        if ($class = ltrim((string) $this->option('flush-failed'), '\\')) {
            if (! Schema::hasTable('failed_jobs')) {
                $this->warn('No failed_jobs table.');
            } else {
                $ids = [];
                DB::table('failed_jobs')->select('id', 'payload')->orderBy('id')->chunk(200, function ($rows) use ($class, &$ids) {
                    foreach ($rows as $row) {
                        $payload = json_decode((string) $row->payload, true);
                        $name = ltrim((string) ($payload['displayName'] ?? $payload['data']['commandName'] ?? ''), '\\');
                        if (strcasecmp($name, $class) === 0) {
                            $ids[] = $row->id;
                        }
                    }
                });
                if (! $dry && $ids !== []) {
                    DB::table('failed_jobs')->whereIn('id', $ids)->delete();
                }
                $this->info(($dry ? 'Would delete ' : 'Deleted ') . count($ids) . " failed job(s) of {$class}.");
            }
        }

        return self::SUCCESS;
    }
}
