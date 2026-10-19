<?php

namespace App\Console\Commands;

use App\Services\Https\CertCtl;
use Illuminate\Console\Command;

/**
 * Root helper entry point (systemd). Fixed allowlist of operations: tick | renew.
 * Takes NO other input: everything else is read from the database and re-validated.
 */
class CertCtlCommand extends Command
{
    protected $signature = 'domaindash:certctl {op=tick : tick|renew}';
    protected $description = 'Platform HTTPS root helper (processes certificate requests / renews). Run by systemd as root.';

    public function handle(CertCtl $ctl): int
    {
        $op = (string) $this->argument('op');

        try {
            $result = match ($op) {
                'tick' => $ctl->tick(),
                'renew' => $ctl->renew(),
                default => null,
            };
        } catch (\Throwable $e) {
            $this->error(substr($e->getMessage(), 0, 500));

            return self::FAILURE;
        }

        if ($result === null) {
            $this->error('Unknown operation. Allowed: tick, renew.');

            return self::INVALID;
        }

        $this->line($op . ': ' . json_encode($result));

        return ($result['ok'] ?? true) ? self::SUCCESS : self::FAILURE;
    }
}
