<?php

namespace App\Enums;

enum RouterStatus: string
{
    case Pending = 'pending';
    case Online = 'online';
    case Offline = 'offline';
    case Disabled = 'disabled';
    case Error = 'error';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Not yet tested',
            self::Online => 'Online',
            self::Offline => 'Offline',
            self::Disabled => 'Disabled',
            self::Error => 'Needs attention',
            self::Archived => 'Archived',
        };
    }

    /** Tailwind badge classes used by the UI. */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Online => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
            self::Pending => 'bg-slate-100 text-slate-600 ring-slate-500/20',
            self::Offline => 'bg-slate-800 text-white ring-slate-900/20',
            self::Disabled => 'bg-amber-50 text-amber-700 ring-amber-600/20',
            self::Error => 'bg-red-50 text-red-700 ring-red-600/20',
            self::Archived => 'bg-slate-100 text-slate-500 ring-slate-400/20',
        };
    }

    public function isMonitorable(): bool
    {
        return ! in_array($this, [self::Disabled, self::Archived], true);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
