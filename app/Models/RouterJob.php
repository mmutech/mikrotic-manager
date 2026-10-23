<?php

namespace App\Models;

use App\Enums\RouterJobStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

class RouterJob extends Model
{
    /** @use HasFactory<\Database\Factories\RouterJobFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid',
        'router_id',
        'type',
        'status',
        'idempotency_key',
        'payload',
        'attempts',
        'max_attempts',
        'available_at',
        'started_at',
        'finished_at',
        'error_category',
        'error_message',
        'related_type',
        'related_id',
        'correlation_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => RouterJobStatus::class,
            'payload' => 'array',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'available_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (RouterJob $job): void {
            $job->uuid ??= (string) Str::uuid();
        });
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    public function isPending(): bool
    {
        return $this->status->isPending();
    }

    public function isSettled(): bool
    {
        return in_array($this->status, [
            \App\Enums\RouterJobStatus::Succeeded,
            \App\Enums\RouterJobStatus::Failed,
            \App\Enums\RouterJobStatus::Cancelled,
        ], true);
    }
}
