<?php

namespace App\Enums;

enum VoucherLifecycleStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Expired = 'expired';
    case Exhausted = 'exhausted';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending activation',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Expired => 'Expired',
            self::Exhausted => 'Data exhausted',
            self::Revoked => 'Revoked',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Active => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
            self::Pending => 'bg-amber-50 text-amber-700 ring-amber-600/20',
            self::Suspended => 'bg-slate-100 text-slate-600 ring-slate-500/20',
            self::Expired, self::Revoked => 'bg-red-50 text-red-700 ring-red-600/20',
            self::Exhausted => 'bg-orange-50 text-orange-700 ring-orange-600/20',
        };
    }

    public function isUsable(): bool
    {
        return in_array($this, [self::Active, self::Pending], true);
    }
}
