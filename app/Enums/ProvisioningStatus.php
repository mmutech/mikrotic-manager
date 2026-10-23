<?php

namespace App\Enums;

enum ProvisioningStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Provisioning = 'provisioning';
    case Provisioned = 'provisioned';
    case Failed = 'failed';
    case Disabling = 'disabling';
    case Disabled = 'disabled';
    case Removing = 'removing';
    case Removed = 'removed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Not provisioned',
            self::Queued => 'Queued',
            self::Provisioning => 'Provisioning',
            self::Provisioned => 'Provisioned',
            self::Failed => 'Provisioning failed',
            self::Disabling => 'Disabling',
            self::Disabled => 'Disabled on router',
            self::Removing => 'Removing',
            self::Removed => 'Removed from router',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Provisioned => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
            self::Queued, self::Provisioning, self::Disabling, self::Removing => 'bg-blue-50 text-blue-700 ring-blue-600/20',
            self::Failed => 'bg-red-50 text-red-700 ring-red-600/20',
            default => 'bg-slate-100 text-slate-600 ring-slate-500/20',
        };
    }

    public function isSettled(): bool
    {
        return in_array($this, [self::Provisioned, self::Disabled, self::Removed, self::Failed], true);
    }
}
