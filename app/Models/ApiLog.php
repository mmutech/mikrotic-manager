<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\MassPrunable;

class ApiLog extends Model
{
    use MassPrunable;

    public $timestamps = false;

    protected $fillable = [
        'router_id',
        'correlation_id',
        'method',
        'endpoint',
        'http_status',
        'latency_ms',
        'success',
        'error_category',
        'error_message',
        'request_meta',
        'response_meta',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'http_status' => 'integer',
            'latency_ms' => 'integer',
            'request_meta' => 'array',
            'response_meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function prunable(): \Illuminate\Database\Eloquent\Builder
    {
        return static::query()->where(
            'created_at',
            '<=',
            now()->subDays((int) config('mikrotik.api_log_retention_days', 30))
        );
    }
}
