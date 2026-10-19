<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class MfaResetCommand extends Command
{
    protected $signature = 'domaindash:mfa-reset {email : Email of the user to reset}';

    protected $description = 'Break-glass: clear a user\'s 2FA enrolment so they can re-enrol (e.g. a locked-out administrator)';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'mfa_prompted_at' => null,
        ])->save();

        \App\Services\AuditLogger::logSystem('mfa.reset-cli', "MFA reset via CLI for {$user->email}.", [
            'service' => 'auth', 'function' => 'mfa-reset-cli',
        ]);

        $this->info("MFA reset for {$user->email}. They must re-enrol at next login.");

        return self::SUCCESS;
    }
}
