<?php

namespace App\Enums;

enum SubscriptionType: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Yearly => 'Yearly',
        };
    }

    public function days(): int
    {
        return match ($this) {
            self::Monthly => 30,
            self::Yearly => 365,
        };
    }

    public static function fromDays(int $days): self
    {
        return $days >= self::Yearly->days() ? self::Yearly : self::Monthly;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return array<int, array{value: string, label: string, days: int}> */
    public static function options(): array
    {
        return array_map(
            fn (self $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
                'days' => $type->days(),
            ],
            self::cases(),
        );
    }
}
