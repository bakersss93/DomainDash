<?php

namespace App\Services\Vocus;

use Illuminate\Support\Facades\Cache;

/**
 * Sliding-window limiter shared by every PHP process through the cache store.
 *
 * Vocus WSM allows 5 requests per 10 seconds at the overall web-service level
 * and blocks the client for 10 seconds when exceeded (WSM-4080). We stay under
 * that by allowing `max` calls per `perSeconds` (default 4/10s) and sleeping
 * until a slot frees up. The cache store must support atomic locks (array,
 * file, database, redis, memcached all do).
 */
class VocusRateLimiter
{
    /** @var callable fn(float $seconds): void */
    protected $sleeper;

    /** @var callable fn(): float */
    protected $clock;

    public function __construct(
        protected int $max = 4,
        protected int $perSeconds = 10,
        protected int $maxWaitSeconds = 15,
        ?callable $sleeper = null,
        ?callable $clock = null,
        protected string $key = 'vocus_wsm_rate_limit',
    ) {
        $this->max = max(1, $max);
        $this->perSeconds = max(1, $perSeconds);
        $this->sleeper = $sleeper ?? static fn (float $s) => usleep((int) ($s * 1_000_000));
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * Block until a request slot is available, then claim it.
     *
     * @throws VocusWsmException when the wait would exceed the maximum wait
     */
    public function acquire(): void
    {
        $deadline = ($this->clock)() + $this->maxWaitSeconds;

        while (true) {
            $wait = $this->tryClaim();

            if ($wait <= 0) {
                return;
            }

            if (($this->clock)() + $wait > $deadline) {
                throw new VocusWsmException(
                    'Vocus request rate limit reached (' . $this->max . ' requests per ' . $this->perSeconds . 's); try again shortly.',
                    'LOCAL-RATE-LIMIT'
                );
            }

            $this->sleep($wait);
        }
    }

    public function sleep(float $seconds): void
    {
        ($this->sleeper)($seconds);
    }

    /**
     * Claim a slot if one is free.
     *
     * @return float 0 when claimed, otherwise seconds until the oldest call leaves the window
     */
    protected function tryClaim(): float
    {
        $lock = Cache::lock($this->key . ':lock', 5);

        return $lock->block(5, function (): float {
            $now = ($this->clock)();
            $cutoff = $now - $this->perSeconds;

            $stamps = array_values(array_filter(
                (array) Cache::get($this->key, []),
                static fn ($t) => is_numeric($t) && $t > $cutoff
            ));

            if (count($stamps) >= $this->max) {
                return max(0.05, ($stamps[0] + $this->perSeconds) - $now);
            }

            $stamps[] = $now;
            Cache::put($this->key, $stamps, $this->perSeconds + 5);

            return 0.0;
        });
    }
}
