<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Validates a router management address against an approved CIDR allowlist.
 *
 * Resolution happens both at form-validation time and immediately before a
 * connection is opened. This does not fully eliminate DNS-rebinding races;
 * production deployments must also restrict outbound traffic at the network
 * layer (see docs/security.md).
 */
final class RouterHostPolicy
{
    /** @var array<int, string> */
    private array $networks;

    public function __construct(?array $networks = null)
    {
        $networks = $networks ?? (array) config('mikrotik.management_networks', []);
        $this->networks = [];

        foreach ($networks as $network) {
            $network = trim((string) $network);

            if ($network === '') {
                continue;
            }

            if (! $this->isValidCidr($network)) {
                Log::warning('Ignoring invalid MikroTik management network entry.', ['cidr' => $network]);

                continue;
            }

            $this->networks[] = $network;
        }
    }

    public function assertAllowed(string $host): void
    {
        if (! $this->isAllowed($host)) {
            throw new \RuntimeException(
                'The router host must resolve only to an approved management network.'
            );
        }
    }

    public function isAllowed(string $host): bool
    {
        $host = trim($host);

        if ($host === '' || strlen($host) > 253) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->ipIsAllowed($host);
        }

        if (! $this->isValidHostname($host)) {
            return false;
        }

        $addresses = $this->resolve($host);

        if ($addresses === []) {
            return false;
        }

        foreach ($addresses as $address) {
            if (! $this->ipIsAllowed($address)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int, string> */
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $addresses = [];

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                $address = $record['ip'] ?? $record['ipv6'] ?? null;

                if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP)) {
                    $addresses[] = $address;
                }
            }
        }

        $ipv4 = @gethostbynamel($host);

        if (is_array($ipv4)) {
            array_push($addresses, ...$ipv4);
        }

        return array_values(array_unique($addresses));
    }

    public function ipIsAllowed(string $ip): bool
    {
        foreach ($this->networks as $network) {
            if ($this->ipInCidr($ip, $network)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    public function networks(): array
    {
        return $this->networks;
    }

    private function isValidHostname(string $host): bool
    {
        if (preg_match('/[\s:@\/?#\\\\]/', $host) === 1) {
            return false;
        }

        $host = rtrim($host, '.');

        if ($host === '' || str_contains($host, '..')) {
            return false;
        }

        return preg_match(
            '/^(?=.{1,253}$)(?!-)[A-Za-z0-9-]{1,63}(?<!-)(\.(?!-)[A-Za-z0-9-]{1,63}(?<!-))*$/',
            $host
        ) === 1;
    }

    private function isValidCidr(string $cidr): bool
    {
        [$network, $prefix] = array_pad(explode('/', $cidr, 2), 2, null);

        if ($prefix === null || ! ctype_digit((string) $prefix)) {
            return false;
        }

        if (filter_var($network, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $prefix = (int) $prefix;
        $max = str_contains($network, ':') ? 128 : 32;

        return $prefix >= 0 && $prefix <= $max;
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        [$network, $prefix] = explode('/', $cidr, 2);
        $prefix = (int) $prefix;

        $ipBinary = inet_pton($ip);
        $networkBinary = inet_pton($network);

        if ($ipBinary === false || $networkBinary === false) {
            return false;
        }

        if (strlen($ipBinary) !== strlen($networkBinary)) {
            return false;
        }

        $wholeBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($wholeBytes > 0
            && substr($ipBinary, 0, $wholeBytes) !== substr($networkBinary, 0, $wholeBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($ipBinary[$wholeBytes]) & $mask) === (ord($networkBinary[$wholeBytes]) & $mask);
    }
}
