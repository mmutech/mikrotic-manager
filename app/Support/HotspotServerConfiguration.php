<?php

namespace App\Support;

use App\Exceptions\RouterProbeException;

final class HotspotServerConfiguration
{
    public const NAME = 'mmutech-hotspot';

    /**
     * @param  array<string, mixed>  $configuration
     * @return array{server_name: string, interface: string, address_pool: string, profile: string}
     */
    public static function normalize(array $configuration): array
    {
        $serverName = $configuration['server_name'] ?? self::NAME;
        $interface = $configuration['interface'] ?? null;
        $addressPool = $configuration['address_pool'] ?? null;
        $profile = $configuration['profile'] ?? null;

        if (! is_string($serverName) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $serverName) !== 1) {
            throw RouterProbeException::configuration('The HotSpot server name must use 1 to 64 letters, numbers, dots, underscores, or hyphens.');
        }

        foreach ([
            'interface' => $interface,
            'address_pool' => $addressPool,
            'profile' => $profile,
        ] as $field => $value) {
            if (! is_string($value) || trim($value) === '' || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw RouterProbeException::configuration('Select a valid value for the HotSpot server '.$field.'.');
            }
        }

        return [
            'server_name' => $serverName,
            'interface' => trim($interface),
            'address_pool' => trim($addressPool),
            'profile' => trim($profile),
        ];
    }

    /**
     * @param  array{server_name: string, interface: string, address_pool: string, profile: string}  $configuration
     * @return array<string, string>
     */
    public static function desiredSettings(array $configuration): array
    {
        return [
            'name' => $configuration['server_name'],
            'interface' => $configuration['interface'],
            'address-pool' => $configuration['address_pool'],
            'profile' => $configuration['profile'],
            'disabled' => 'no',
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<int, string>
     */
    public static function terminalCommands(array $configuration): array
    {
        $settings = self::desiredSettings(self::normalize($configuration));
        $arguments = [];

        foreach ($settings as $key => $value) {
            $arguments[] = $key.'='.self::terminalValue($value);
        }

        return [
            '/ip hotspot add '.implode(' ', $arguments),
            '/ip hotspot print detail',
        ];
    }

    private static function terminalValue(string $value): string
    {
        if (preg_match('~\A[A-Za-z0-9._:/,!*-]+\z~D', $value) === 1) {
            return $value;
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
