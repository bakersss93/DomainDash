<?php

namespace Tests\Feature\Security;

use App\Casts\EncryptedSettingValue;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_value_round_trips_and_is_encrypted_at_rest(): void
    {
        Setting::put('halo', ['api_key' => 'super-secret-key', 'tenant' => 'acme']);

        $raw = DB::table('settings')->where('key', 'halo')->value('value');
        $this->assertStringStartsWith(EncryptedSettingValue::PREFIX, $raw);
        $this->assertStringNotContainsString('super-secret-key', $raw);

        $this->assertSame(['api_key' => 'super-secret-key', 'tenant' => 'acme'], Setting::get('halo'));
    }

    public function test_legacy_plaintext_rows_are_still_readable(): void
    {
        DB::table('settings')->insert([
            'key' => 'smtp', 'value' => json_encode(['password' => 'legacy-pw']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(['password' => 'legacy-pw'], Setting::get('smtp'));
    }

    public function test_migration_encrypts_plaintext_idempotently(): void
    {
        DB::table('settings')->insert([
            'key' => 'itglue', 'value' => json_encode(['api_key' => 'plain-key']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_10_19_000300_encrypt_settings_values.php');
        $migration->up();
        $first = DB::table('settings')->where('key', 'itglue')->value('value');
        $migration->up();
        $second = DB::table('settings')->where('key', 'itglue')->value('value');

        $this->assertStringStartsWith(EncryptedSettingValue::PREFIX, $first);
        $this->assertSame($first, $second, 'second run must not re-encrypt');
        $this->assertStringNotContainsString('plain-key', $first);
        $this->assertSame(['api_key' => 'plain-key'], Setting::get('itglue'));
    }

    public function test_wrong_key_data_reads_as_default(): void
    {
        DB::table('settings')->insert([
            'key' => 'broken', 'value' => EncryptedSettingValue::PREFIX . 'not-a-valid-payload',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame('fallback', Setting::get('broken', 'fallback'));
    }
}
