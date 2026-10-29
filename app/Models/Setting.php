<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key','value'];

    /** Values (which include vendor credentials) are encrypted at rest. */
    protected $casts = ['value' => \App\Casts\EncryptedSettingValue::class];

    public static function get(string $key, $default = null){
        return optional(static::where('key',$key)->first())->value ?? $default;
    }

    public static function put(string $key, $value): void {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
