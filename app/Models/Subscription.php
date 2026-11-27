<?php

namespace App\Models;

use App\Enums\SubscriptionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'router_id',
        'plan',
        'type',
        'status',
        'starts_at',
        'expires_at',
        'amount',
        'currency',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'amount' => 'decimal:2',
            'type' => SubscriptionType::class,
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->expires_at !== null && $this->expires_at->isFuture();
    }

    public function typeLabel(): string
    {
        return $this->type->label();
    }

    public function durationDays(): int
    {
        return $this->type->days();
    }

    public function daysRemaining(): int
    {
        if ($this->expires_at === null) {
            return 0;
        }

        return max(0, (int) now()->diffInDays($this->expires_at, false));
    }
}
