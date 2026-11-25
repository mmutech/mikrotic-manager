<?php

namespace App\Support;

use App\Exceptions\RouterProbeException;
use App\Models\Router;

final class HotspotServerConfiguration
{
    /**
     * Resolve the HotSpot server name from the router identity.
     *
     * RouterOS identity is the single source of truth for the managed server
     * name. An empty identity falls back to the router name.
     */
    public static function nameFromRouter(Router $router): string
    {
        return RouterIdentity::fromRouter($router);
    }

    /**
     * Resolve the HotSpot address pool from the router identity.
     *
     * The managed network provisioning creates the DHCP pool with this exact
     * identity, so the HotSpot server must use the same name.
     */
    public static function addressPoolFromRouter(Router $router): string
    {
        return RouterIdentity::fromRouter($router);
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array{server_name: string, interface: string, address_pool: string, profile: string}
     */
    public static function normalize(array $configuration): array
    {
        $serverName = $configuration['server_name'] ?? null;
        $serverName = is_string($serverName) ? trim($serverName) : '';
        $interface = $configuration['interface'] ?? null;
        $interface = is_string($interface) ? trim($interface) : null;
        $addressPool = $configuration['address_pool'] ?? null;
        $addressPool = is_string($addressPool) ? trim($addressPool) : null;
        $profile = $configuration['profile'] ?? null;
        $profile = is_string($profile) ? trim($profile) : null;

        if ($serverName === '' || mb_strlen($serverName) > RouterIdentity::MAX_LENGTH
            || preg_match('/[\x00-\x1F\x7F]/u', $serverName) === 1) {
            throw RouterProbeException::configuration('The HotSpot server name must be the router identity, 1 to 64 characters, and cannot contain control characters.');
        }

        foreach ([
            'interface' => $interface,
            'address_pool' => $addressPool,
            'profile' => $profile,
        ] as $field => $value) {
            if ($value === null || $value === '' || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw RouterProbeException::configuration('Select a valid value for the HotSpot server '.$field.'.');
            }
        }

        if ($addressPool !== $serverName) {
            throw RouterProbeException::configuration('The HotSpot address pool must be the router identity.');
        }

        return [
            'server_name' => $serverName,
            'interface' => (string) $interface,
            'address_pool' => (string) $addressPool,
            'profile' => (string) $profile,
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
