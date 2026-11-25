<?php

namespace App\Support;

final class Ipv4Cidr
{
    private const MIN_PREFIX = 8;

    private const MAX_PREFIX = 32;

    /**
     * Return the canonical RouterOS network/prefix form for an IPv4 value.
     */
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (! str_contains($value, '/')) {
            $value .= '/32';
        }

        $parts = explode('/', $value);

        if (count($parts) !== 2) {
            return null;
        }

        [$address, $prefixValue] = $parts;

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || filter_var($prefixValue, FILTER_VALIDATE_INT) === false) {
            return null;
        }

        $prefix = (int) $prefixValue;

        if ($prefix < self::MIN_PREFIX || $prefix > self::MAX_PREFIX) {
            return null;
        }

        $addressLong = (int) sprintf('%u', ip2long($address));
        $networkLong = $addressLong & self::mask($prefix);

        return long2ip($networkLong).'/'.$prefix;
    }

    public static function contains(string $cidr, string $address): bool
    {
        $normalized = self::normalize($cidr);

        if ($normalized === null || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        [$network, $prefix] = explode('/', $normalized, 2);
        $mask = self::mask((int) $prefix);

        $networkLong = (int) sprintf('%u', ip2long($network));
        $addressLong = (int) sprintf('%u', ip2long($address));

        return ($addressLong & $mask) === ($networkLong & $mask);
    }

    /**
     * @param  iterable<mixed>  $values
     * @return array<int, string>
     */
    public static function normalizeList(iterable $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            $cidr = self::normalize($value);

            if ($cidr !== null) {
                $normalized[$cidr] = true;
            }
        }

        return array_keys($normalized);
    }

    /**
     * @return array<int, string>
     */
    public static function splitList(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        return preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private static function mask(int $prefix): int
    {
        return (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
    }
}
