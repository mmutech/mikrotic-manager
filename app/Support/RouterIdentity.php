<?php

namespace App\Support;

use App\Exceptions\RouterProbeException;

final class RouterIdentity
{
    public const MAX_LENGTH = 64;

    /**
     * Resolve the identity to apply to RouterOS.
     *
     * An empty value falls back to the router name so existing routers keep
     * their current behaviour. RouterOS identities may contain spaces, but are
     * limited to 64 characters and cannot contain control characters.
     */
    public static function normalize(mixed $identity, string $fallback): string
    {
        $value = is_string($identity) ? trim($identity) : '';

        if ($value === '') {
            $value = trim($fallback);
        }

        if ($value === '' || mb_strlen($value) > self::MAX_LENGTH
            || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw RouterProbeException::configuration(
                'The RouterOS identity must be 1 to 64 characters and cannot contain control characters.',
            );
        }

        return $value;
    }
}
