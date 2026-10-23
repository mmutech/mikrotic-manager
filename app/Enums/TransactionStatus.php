<?php

namespace App\Enums;

enum TransactionStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Abandoned = 'abandoned';
    case Refunded = 'refunded';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Paid => 'Paid',
            self::Failed => 'Failed',
            self::Abandoned => 'Abandoned',
            self::Refunded => 'Refunded',
            self::Reversed => 'Reversed',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Paid => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
            self::Pending => 'bg-amber-50 text-amber-700 ring-amber-600/20',
            self::Failed, self::Reversed => 'bg-red-50 text-red-700 ring-red-600/20',
            default => 'bg-slate-100 text-slate-600 ring-slate-500/20',
        };
    }

    public function isSettled(): bool
    {
        return $this !== self::Pending;
    }
}
