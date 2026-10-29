<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Stores the settings value as encrypted JSON ("enc:v1:<ciphertext>").
 *
 * Reads are backward compatible: legacy plaintext JSON rows (written before
 * encryption was introduced) are still decoded, and are re-encrypted by the
 * 2026_10_19_000300 migration or the next time the row is saved.
 */
class EncryptedSettingValue implements CastsAttributes
{
    public const PREFIX = 'enc:v1:';

    /** @var array<string,bool> setting keys that failed to decrypt in this process */
    public static array $decryptFailures = [];

    public static function isEncrypted(?string $raw): bool
    {
        return is_string($raw) && str_starts_with($raw, self::PREFIX);
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (self::isEncrypted($value)) {
            try {
                $value = Crypt::decryptString(substr($value, strlen(self::PREFIX)));
            } catch (DecryptException) {
                self::$decryptFailures[(string) ($attributes['key'] ?? $key)] = true;

                return null; // wrong APP_KEY / corrupted: behave as "not set"
            }
        }

        return json_decode($value, true);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        return self::PREFIX . Crypt::encryptString(json_encode($value));
    }
}
