<?php

namespace App\Models;

use App\Enums\ProvisioningStatus;
use App\Enums\RouterErrorCategory;
use App\Enums\RouterJobStatus;
use App\Enums\RouterStatus;
use App\Enums\VoucherLifecycleStatus;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Router extends Model
{
    /** @use HasFactory<\Database\Factories\RouterFactory> */
    use HasFactory, HasPublicId, SoftDeletes;

    protected $fillable = [
        'name',
        'location',
        'site',
        'host',
        'rest_port',
        'api_username',
        'api_password',
        'tls_verify',
        'api_password_rotated_at',
        'monitoring_enabled',
        'notes',
        'created_by',
    ];

    protected $hidden = [
        'api_password',
    ];

    protected function casts(): array
    {
        return [
            'api_password' => 'encrypted',
            'tls_verify' => 'boolean',
            'monitoring_enabled' => 'boolean',
            'status' => RouterStatus::class,
            'last_error_category' => RouterErrorCategory::class,
            'api_password_rotated_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'consecutive_failures' => 'integer',
            'latency_ms' => 'integer',
            'metadata' => 'array',
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

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('granted_by')
            ->withTimestamps();
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'router_plan_profiles')
            ->withPivot(['hotspot_profile', 'last_synchronized_at'])
            ->withTimestamps();
    }

    public function profileMappings(): HasMany
    {
        return $this->hasMany(RouterPlanProfile::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function hotspotSessions(): HasMany
    {
        return $this->hasMany(HotspotSession::class);
    }

    public function apiLogs(): HasMany
    {
        return $this->hasMany(ApiLog::class);
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(RouterJob::class);
    }

    public function healthChecks(): HasMany
    {
        return $this->hasMany(RouterHealthCheck::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isSuperAdmin()) {
            return $query;
        }

        return $query->whereHas('users', fn (Builder $query) => $query->whereKey($user->getKey()));
    }

    public function scopeMonitoring(Builder $query): Builder
    {
        return $query->where('monitoring_enabled', true)
            ->whereNotIn('status', [RouterStatus::Disabled->value, RouterStatus::Archived->value]);
    }

    public function isTls(): bool
    {
        return in_array($this->rest_port, [443, 8729], true);
    }

    public function tlsIsHttps(): bool
    {
        return $this->isTls();
    }

    public function isOnline(): bool
    {
        return $this->status === RouterStatus::Online;
    }

    public function isMonitoring(): bool
    {
        return $this->monitoring_enabled
            && ! in_array($this->status, [RouterStatus::Disabled, RouterStatus::Archived], true);
    }

    /**
     * Never include api_password in audit payloads or API responses.
     *
     * @return array<string, mixed>
     */
    public function auditSnapshot(): array
    {
        return collect($this->attributesToArray())
            ->except(['api_password'])
            ->all();
    }

    public function provisioningSummary(): string
    {
        $provisioned = $this->vouchers()
            ->where('provisioning_status', ProvisioningStatus::Provisioned->value)
            ->count();
        $failed = $this->vouchers()
            ->where('provisioning_status', ProvisioningStatus::Failed->value)
            ->count();

        return "{$provisioned} provisioned, {$failed} failed";
    }

    public function usableVoucherCount(): int
    {
        return $this->vouchers()
            ->whereIn('lifecycle_status', [
                VoucherLifecycleStatus::Active->value,
                VoucherLifecycleStatus::Pending->value,
            ])
            ->count();
    }

    public function pendingJobCount(): int
    {
        return $this->jobs()->whereIn('status', [
            RouterJobStatus::Queued->value,
            RouterJobStatus::Running->value,
            RouterJobStatus::Retrying->value,
        ])->count();
    }
}
