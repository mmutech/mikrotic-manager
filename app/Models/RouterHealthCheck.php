<?php

namespace App\Models;

use App\Enums\HealthCheckStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouterHealthCheck extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'router_id',
        'status',
        'latency_ms',
        'identity',
        'ros_version',
        'error_category',
        'details',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => HealthCheckStatus::class,
            'latency_ms' => 'integer',
            'details' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }
}
