<?php

namespace App\Models;

use App\Enums\UserStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'status',
        'last_login_at',
        'password_changed_at',
        'must_change_password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'must_change_password' => 'boolean',
        ];
    }

    public function customer(): HasOne
    {
        return $this->hasOne(Customer::class);
    }

    public function routers(): BelongsToMany
    {
        return $this->belongsToMany(Router::class)
            ->withPivot('granted_by')
            ->withTimestamps();
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_id')
            ->where('actor_type', self::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function activeSubscription(): ?Subscription
    {
        return $this->subscriptions()
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->latest('expires_at')
            ->first();
    }

    public function requiresSubscription(): bool
    {
        return $this->hasRole('network-admin');
    }

    /**
     * Network administrators must hold a valid subscription to operate.
     */
    public function hasActiveSubscription(): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }
        if (! $this->requiresSubscription()) {
            return true;
        }
        return $this->activeSubscription() !== null;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active && ! $this->trashed();
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super-admin');
    }

    /**
     * Super administrators can reach every router. Everyone else needs an
     * explicit assignment in router_user (defence in depth on top of policies).
     */
    public function canAccessRouter(Router $router): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        if (! $router->exists) {
            return false;
        }

        return $this->routers()->whereKey($router->getKey())->exists();
    }

    public function accessibleRouterIds(): array
    {
        if ($this->isSuperAdmin()) {
            return [];
        }
        return $this->routers()->pluck('routers.id')->all();
    }

    /** @return array<int, string> */
    public function roleLabels(): array
    {
        return $this->roles->pluck('name')->map(
            fn (string $name): string => str($name)->replace('-', ' ')->title()->toString()
        )->all();
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];
        $initials = '';

        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return $initials !== '' ? $initials : 'U';
    }
}
