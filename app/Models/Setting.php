<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    protected $fillable = ['key','value'];

    protected $casts = ['value' => 'array'];

    // Setting groups whose values must be encrypted at rest.
    public const SENSITIVE_GROUPS = ['synergy', 'halo', 'smtp', 'backup', 'itglue', 'ip2whois'];

    // Sub-keys within sensitive groups that must be redacted in audit logs.
    public const SENSITIVE_SUBKEYS = ['api_key', 'password', 'secret', 'client_secret'];

    public static function get(string $key, $default = null){
        return optional(static::where('key',$key)->first())->value ?? $default;
    }

    public static function put(string $key, $value): void {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /**
     * Store a sensitive setting group encrypted at rest.
     * The value array is JSON-encoded then AES-encrypted; the cipher is wrapped
     * in {"_enc": "..."} so the existing array cast stores/retrieves it cleanly.
     */
    public static function putSensitive(string $key, array $value): void
    {
        $cipher = Crypt::encryptString(json_encode($value));
        static::updateOrCreate(['key' => $key], ['value' => ['_enc' => $cipher]]);
    }

    /**
     * Retrieve a sensitive setting group, decrypting if stored encrypted.
     * Falls back gracefully to plaintext legacy rows.
     */
    public static function getSensitive(string $key, array $default = []): array
    {
        $row = static::where('key', $key)->first();
        if (! $row) {
            return $default;
        }

        $stored = $row->value; // already array-cast by Eloquent

        if (is_array($stored) && isset($stored['_enc'])) {
            try {
                $decoded = json_decode(Crypt::decryptString($stored['_enc']), true);
                return is_array($decoded) ? $decoded : $default;
            } catch (\Exception $e) {
                return $default;
            }
        }

        // Legacy plaintext row — return as-is so the app keeps working until
        // the next settings save re-encrypts it.
        return is_array($stored) ? $stored : $default;
    }
}
