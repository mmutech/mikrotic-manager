<?php

namespace App\Models;

use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    /** @use HasFactory<\Database\Factories\CustomerFactory> */
    use HasFactory, HasPublicId, SoftDeletes;

    protected $fillable = [
        'user_id',
        'code',
        'name',
        'email',
        'phone',
        'status',
        'notes',
        'created_by',
    ];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($term)).'%';

        return $query->where(function (Builder $query) use ($term): void {
            $query->where('name', 'like', $term)
                ->orWhere('code', 'like', $term)
                ->orWhere('email', 'like', $term)
                ->orWhere('phone', 'like', $term);
        });
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && ! $this->trashed();
    }
}
