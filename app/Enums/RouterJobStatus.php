<?php

namespace App\Enums;

enum RouterJobStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Retrying = 'retrying';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Running => 'Running',
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
            self::Retrying => 'Retrying',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isPending(): bool
    {
        return in_array($this, [self::Queued, self::Retrying, self::Running], true);
    }
}
