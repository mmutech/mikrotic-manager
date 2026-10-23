<?php

namespace App\Models;

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

    public function daysRemaining(): int
    {
        if ($this->expires_at === null) {
            return 0;
        }
        return max(0, (int) now()->diffInDays($this->expires_at, false));
    }
}
