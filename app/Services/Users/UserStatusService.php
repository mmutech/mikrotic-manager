<?php

namespace App\Services\Users;

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\AuditLogger;

final class UserStatusService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function change(User $target, UserStatus $status, User $actor): bool
    {
        if ($target->status === $status || $target->getKey() === $actor->getKey()) {
            return false;
        }

        $before = ['status' => $target->status->value];

        $target->update(['status' => $status]);

        $this->audit->record(
            'user.status_changed',
            $target,
            before: $before,
            after: ['status' => $status->value],
            actor: $actor,
            metadata: ['status' => $status->value],
        );

        return true;
    }
}
