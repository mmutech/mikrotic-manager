<?php

namespace App\Support;

use Illuminate\Support\Str;

final class CorrelationContext
{
    private ?string $id = null;

    public function id(): string
    {
        return $this->id ??= (string) Str::uuid();
    }

    public function set(?string $id): string
    {
        $this->id = $id !== null && preg_match('/^[A-Za-z0-9._-]{8,64}$/', $id) === 1
            ? $id
            : (string) Str::uuid();

        return $this->id;
    }

    public function reset(): void
    {
        $this->id = null;
    }
}
