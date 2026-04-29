<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use App\Models\Setting;

/**
 * One-time migration: re-encrypts any sensitive setting groups that are
 * currently stored as plaintext JSON in the settings table.
 *
 * Safe to re-run: rows already using the {"_enc":"..."} envelope are skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Setting::SENSITIVE_GROUPS as $key) {
            $row = DB::table('settings')->where('key', $key)->first();
            if (! $row) {
                continue;
            }

            $decoded = json_decode($row->value, true);

            // Already encrypted — the envelope has a single "_enc" key.
            if (is_array($decoded) && array_keys($decoded) === ['_enc']) {
                continue;
            }

            // Plaintext array — encrypt it now.
            if (is_array($decoded) && ! empty($decoded)) {
                $cipher = Crypt::encryptString(json_encode($decoded));
                DB::table('settings')
                    ->where('key', $key)
                    ->update(['value' => json_encode(['_enc' => $cipher])]);
            }
        }
    }

    public function down(): void
    {
        // Intentionally not reversible: we do not want to decrypt secrets back
        // to plaintext on a rollback. If a rollback is needed, re-deploy the
        // prior application version (which uses Setting::get) first.
    }
};
