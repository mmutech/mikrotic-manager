<?php

namespace App\Enums;

enum HotspotSessionStatus: string
{
    case Active = 'active';
    case Ended = 'ended';
    case Stale = 'stale';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Ended => 'Ended',
            self::Stale => 'Stale',
            self::Unknown => 'Unknown',
        };
    }
}
