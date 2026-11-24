<?php

namespace App\Support;

use InvalidArgumentException;

class HotspotRateLimit
{
    /**
     * Return the RouterOS-compatible canonical form used by profile creation.
     *
     * @throws InvalidArgumentException
     */
    public static function canonical(?string $rateLimit): ?string
    {
        $speeds = self::speedsKbps($rateLimit);

        if ($speeds === null) {
            return null;
        }

        return $speeds['upload'].'k/'.$speeds['download'].'k';
    }

    /**
     * @return array{upload: int, download: int}|null
     */
    public static function speedsKbps(?string $rateLimit): ?array
    {
        if (blank($rateLimit)) {
            return null;
        }

        $parts = preg_split('/\s+/', trim($rateLimit), 2);
        $ratePair = explode('/', $parts[0] ?? '', 2);

        if (count($ratePair) !== 2) {
            throw new InvalidArgumentException('The RouterOS rate limit must contain upload and download rates.');
        }

        $upload = self::parseRateKbps($ratePair[0]);
        $download = self::parseRateKbps($ratePair[1]);

        if ($upload === 0 && $download === 0) {
            return null;
        }

        if ($upload === 0 || $download === 0) {
            throw new InvalidArgumentException('The RouterOS rate limit must specify both upload and download rates.');
        }

        return [
            'upload' => $upload,
            'download' => $download,
        ];
    }

    private static function parseRateKbps(string $rate): int
    {
        if (preg_match('/\A(?<value>\d+(?:\.\d+)?)(?<unit>[kKmMgG]?)\z/', $rate, $matches) !== 1) {
            throw new InvalidArgumentException('The RouterOS rate limit contains an unsupported rate value.');
        }

        $multiplier = match (strtolower($matches['unit'])) {
            'k' => 1_000,
            'm' => 1_000_000,
            'g' => 1_000_000_000,
            default => 1,
        };
        $kbps = (float) $matches['value'] * $multiplier / 1_000;

        if (! is_finite($kbps) || $kbps > 4_294_967_295 || abs($kbps - round($kbps)) > 0.0000001) {
            throw new InvalidArgumentException('The RouterOS rate limit cannot be represented as whole-number kbps.');
        }

        return (int) round($kbps);
    }
}
