<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * User now implements MustVerifyEmail. Accounts created by administrators / the
 * API before this change have never needed verification, so mark them verified
 * to avoid locking them out. Idempotent: only touches rows still NULL.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        // Irreversible by design.
    }
};
