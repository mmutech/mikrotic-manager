<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\CorrelationContext;
use App\Support\Redactor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditLogger
{
    public function __construct(
        private readonly Redactor $redactor,
        private readonly CorrelationContext $correlation,
    ) {}

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $action,
        ?Model $auditable = null,
        array $before = [],
        array $after = [],
        array $metadata = [],
        ?Model $actor = null,
    ): AuditLog {
        $actor ??= Auth::user();

        return AuditLog::create([
            'correlation_id' => $this->correlation->id(),
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'before' => $before === [] ? null : $this->redactor->redact($before),
            'after' => $after === [] ? null : $this->redactor->redact($after),
            'metadata' => $metadata === [] ? null : $this->redactor->redact($metadata),
            'ip_address' => $this->ipAddress(),
            'user_agent' => $this->userAgent(),
            'created_at' => now(),
        ]);
    }

    private function ipAddress(): ?string
    {
        try {
            return Request::ip();
        } catch (\Throwable) {
            return null;
        }
    }

    private function userAgent(): ?string
    {
        try {
            return mb_substr((string) Request::userAgent(), 0, 512);
        } catch (\Throwable) {
            return null;
        }
    }
}
