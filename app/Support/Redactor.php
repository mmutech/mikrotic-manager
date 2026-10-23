<?php

namespace App\Support;

use Illuminate\Support\Str;

final class Redactor
{
    private const MASK = '[REDACTED]';

    /** @var array<int, string> */
    private array $sensitiveFragments = [
        'password',
        'secret',
        'token',
        'api_key',
        'apikey',
        'private_key',
        'credential',
        'authorization',
        'cookie',
        'pin',
    ];

    public function redact(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && $this->isSensitiveKey($key)) {
            return self::MASK;
        }

        if (is_array($value)) {
            $redacted = [];

            foreach ($value as $childKey => $childValue) {
                $redacted[$childKey] = $this->redact($childValue, (string) $childKey);
            }

            return $redacted;
        }

        if (is_object($value)) {
            return $this->redact($this->objectToArray($value));
        }

        if (is_string($value)) {
            return $this->scrubString($value);
        }

        return $value;
    }

    public function scrubString(string $value): string
    {
        $value = preg_replace(
            '/(Basic|Bearer)\s+[A-Za-z0-9\-._~+\/]+=*/i',
            '$1 '.self::MASK,
            $value
        ) ?? $value;

        $value = preg_replace('#(https?://)[^/@\s]+@#i', '$1'.self::MASK.'@', $value) ?? $value;

        if (Str::length($value) > 2000) {
            $value = Str::limit($value, 2000, '…');
        }

        return $value;
    }

    public function isSensitiveKey(string $key): bool
    {
        $normalised = strtolower($key);

        foreach ($this->sensitiveFragments as $fragment) {
            if (str_contains($normalised, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function objectToArray(object $value): array
    {
        if ($value instanceof \JsonSerializable) {
            $data = $value->jsonSerialize();

            return is_array($data) ? $data : ['value' => $data];
        }

        if ($value instanceof \Illuminate\Contracts\Support\Arrayable) {
            return $value->toArray();
        }

        return get_object_vars($value);
    }
}
