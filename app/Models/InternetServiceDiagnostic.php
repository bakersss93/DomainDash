<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InternetServiceDiagnostic extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'internet_service_id', 'diagnostic_type', 'transaction_id',
        'transaction_state', 'result', 'run_by_user_id', 'created_at',
    ];

    protected $casts = [
        'result'     => 'array',
        'created_at' => 'datetime',
    ];

    public function service()
    {
        return $this->belongsTo(InternetService::class, 'internet_service_id');
    }

    public function runBy()
    {
        return $this->belongsTo(User::class, 'run_by_user_id');
    }
}
