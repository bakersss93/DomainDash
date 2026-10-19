<?php

use App\Casts\EncryptedSettingValue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Encrypts existing plaintext settings values in place. Idempotent: rows already
 * carrying the "enc:v1:" prefix are skipped, so it is safe to re-run.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->orderBy('id')->each(function ($row) {
            if ($row->value === null || $row->value === '' || EncryptedSettingValue::isEncrypted($row->value)) {
                return;
            }

            DB::table('settings')->where('id', $row->id)->update([
                'value' => EncryptedSettingValue::PREFIX . Crypt::encryptString($row->value),
            ]);
        });
    }

    public function down(): void
    {
        DB::table('settings')->orderBy('id')->each(function ($row) {
            if (! EncryptedSettingValue::isEncrypted($row->value)) {
                return;
            }

            DB::table('settings')->where('id', $row->id)->update([
                'value' => Crypt::decryptString(substr($row->value, strlen(EncryptedSettingValue::PREFIX))),
            ]);
        });
    }
};
