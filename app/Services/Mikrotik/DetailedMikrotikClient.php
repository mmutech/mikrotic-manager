<?php

namespace App\Services\Mikrotik;

use Mivo\MikrotikRos7\Client;

/**
 * RouterOS client that retains the raw error body discarded by the vendor client.
 *
 * The installed ResponseParser turns RouterOS errors such as
 * `{"error":400,"message":"Bad Request","detail":"input does not match any value of interface"}`
 * into the unhelpful message "HTTP 400: 400". The vendor client still emits the
 * raw body through its debug hook, so we capture it here without changing vendor code.
 */
class DetailedMikrotikClient extends Client
{
    private ?string $lastResponseBody = null;

    public function lastResponseBody(): ?string
    {
        return $this->lastResponseBody;
    }

    public function lastRouterOsError(): ?string
    {
        $body = $this->lastResponseBody;

        if ($body === null || trim($body) === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            $parts = [];

            foreach (['detail', 'message', 'error'] as $key) {
                $value = $decoded[$key] ?? null;

                if (is_scalar($value)) {
                    $value = trim((string) $value);

                    if ($value !== '' && ! in_array($value, $parts, true)) {
                        $parts[] = $value;
                    }
                }
            }

            if ($parts !== []) {
                return implode(' - ', $parts);
            }
        }

        return trim($body);
    }

    protected function debug(string $message): void
    {
        if (str_starts_with($message, '>>> [REST]')) {
            $this->lastResponseBody = null;
        }

        if (preg_match('/\A<<< \[REST RESPONSE (\d{3})\]\s+(.*)\z/s', $message, $matches) === 1) {
            $status = (int) $matches[1];
            $this->lastResponseBody = $status >= 400 ? $matches[2] : null;
        }

        parent::debug($message);
    }
}
