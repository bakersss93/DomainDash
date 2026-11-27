<?php

namespace App\Console\Commands;

use App\Services\Https\AcmeDnsHook;
use Illuminate\Console\Command;

/** Invoked only by the fixed wrapper scripts certbot calls as hooks. Reads CERTBOT_* from the environment. */
class AcmeDnsHookCommand extends Command
{
    protected $signature = 'domaindash:acme-dns {phase : auth|cleanup}';
    protected $description = 'certbot DNS-01 manual hook (Synergy TXT record)';

    public function handle(AcmeDnsHook $hook): int
    {
        $phase = (string) $this->argument('phase');
        if (! in_array($phase, ['auth', 'cleanup'], true)) {
            $this->error('phase must be auth or cleanup');

            return self::INVALID;
        }

        $domain = (string) getenv('CERTBOT_DOMAIN');
        $validation = (string) getenv('CERTBOT_VALIDATION');

        try {
            $this->line($phase === 'auth' ? $hook->auth($domain, $validation) : $hook->cleanup($domain, $validation));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
