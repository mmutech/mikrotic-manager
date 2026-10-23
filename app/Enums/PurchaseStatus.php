<?php

namespace App\Enums;

enum PurchaseStatus: string
{
    case PendingPayment = 'pending_payment';
    case Paid = 'paid';
    case Provisioning = 'provisioning';
    case Fulfilled = 'fulfilled';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'Awaiting payment',
            self::Paid => 'Paid',
            self::Provisioning => 'Provisioning vouchers',
            self::Fulfilled => 'Fulfilled',
            self::Failed => 'Fulfilment failed',
            self::Cancelled => 'Cancelled',
            self::Refunded => 'Refunded',
        };
    }
}
