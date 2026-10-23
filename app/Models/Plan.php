<?php

namespace App\Models;

use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Plan extends Model
{
    /** @use HasFactory<\Database\Factories\PlanFactory> */
    use HasFactory, HasPublicId, SoftDeletes;

    protected $fillable = [
        'router_id',
        'name',
        'code',
        'description',
        'price',
        'currency',
        'data_allowance_bytes',
        'is_unlimited',
        'validity_seconds',
        'validity_starts',
        'time_allowance_seconds',
        'download_speed_kbps',
        'upload_speed_kbps',
        'activation_policy',
        'expiry_policy',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'data_allowance_bytes' => 'integer',
            'is_unlimited' => 'boolean',
            'validity_seconds' => 'integer',
            'time_allowance_seconds' => 'integer',
            'download_speed_kbps' => 'integer',
            'upload_speed_kbps' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function routers(): BelongsToMany
    {
        return $this->belongsToMany(Router::class, 'router_plan_profiles')
            ->withPivot(['hotspot_profile', 'last_synchronized_at'])
            ->withTimestamps();
    }

    public function profileMappings(): HasMany
    {
        return $this->hasMany(RouterPlanProfile::class);
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function quotaLabel(): string
    {
        if ($this->is_unlimited || $this->data_allowance_bytes === null) {
            return 'Unlimited data';
        }

        $bytes = (int) $this->data_allowance_bytes;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $power = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $power < count($units) - 1) {
            $value /= 1024;
            $power++;
        }

        return number_format($value, $value >= 10 ? 0 : 1).' '.$units[$power];
    }

    public function validityLabel(): ?string
    {
        if (! $this->validity_seconds) {
            return null;
        }

        $seconds = (int) $this->validity_seconds;

        if ($seconds % 86400 === 0) {
            return ($seconds / 86400).' day(s)';
        }

        if ($seconds % 3600 === 0) {
            return ($seconds / 3600).' hour(s)';
        }

        return $seconds.' second(s)';
    }
}
