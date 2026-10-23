<?php

namespace App\Models;

use App\Enums\ProvisioningStatus;
use App\Enums\VoucherLifecycleStatus;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Voucher extends Model
{
    /** @use HasFactory<\Database\Factories\VoucherFactory> */
    use HasFactory, HasPublicId;

    protected $fillable = [
        'router_id',
        'plan_id',
        'customer_id',
        'purchase_id',
        'generated_by',
        'username',
        'password',
        'data_allowance_bytes',
        'time_allowance_seconds',
        'validity_seconds',
        'validity_starts',
        'expiry_policy',
        'lifecycle_status',
        'provisioning_status',
        'activated_at',
        'first_used_at',
        'expires_at',
        'last_synced_at',
        'provisioned_at',
        'revoked_at',
        'revoked_by',
        'used_bytes',
        'time_used_seconds',
        'router_user_id',
        'provisioning_error',
        'notes',
        'metadata',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'lifecycle_status' => VoucherLifecycleStatus::class,
            'provisioning_status' => ProvisioningStatus::class,
            'data_allowance_bytes' => 'integer',
            'time_allowance_seconds' => 'integer',
            'validity_seconds' => 'integer',
            'used_bytes' => 'integer',
            'time_used_seconds' => 'integer',
            'activated_at' => 'datetime',
            'first_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'provisioned_at' => 'datetime',
            'revoked_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(HotspotSession::class);
    }

    public function usageRecords(): HasMany
    {
        return $this->hasMany(VoucherUsage::class);
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereIn('lifecycle_status', [
            VoucherLifecycleStatus::Active->value,
            VoucherLifecycleStatus::Pending->value,
        ]);
    }

    public function scopeExpiringBefore(Builder $query, \DateTimeInterface $date): Builder
    {
        return $query->whereNotNull('expires_at')->where('expires_at', '<=', $date);
    }

    public function remainingBytes(): ?int
    {
        if ($this->data_allowance_bytes === null) {
            return null;
        }

        return max(0, (int) $this->data_allowance_bytes - (int) $this->used_bytes);
    }

    public function isExhausted(): bool
    {
        return $this->data_allowance_bytes !== null
            && $this->used_bytes >= $this->data_allowance_bytes;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        if (! $this->lifecycle_status->isUsable()) {
            return false;
        }

        if ($this->provisioning_status !== ProvisioningStatus::Provisioned) {
            return false;
        }

        if ($this->isExpired() || $this->isExhausted()) {
            return false;
        }

        if ($this->time_allowance_seconds !== null
            && $this->time_used_seconds >= $this->time_allowance_seconds) {
            return false;
        }

        return true;
    }

    /**
     * Voucher credentials are only decrypted through this explicit accessor.
     * List screens never call it.
     */
    public function plainPassword(): ?string
    {
        return $this->password;
    }

    /** @return array<string, mixed> */
    public function auditSnapshot(): array
    {
        return collect($this->attributesToArray())
            ->except(['password', 'router_user_id'])
            ->all();
    }
}
