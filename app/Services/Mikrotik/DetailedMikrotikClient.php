<?php

namespace App\Services\Mikrotik;

use Mivo\MikrotikRos7\Client;
use Mivo\MikrotikRos7\Exceptions\MikrotikException;
use Mivo\MikrotikRos7\Http\ResponseParser;

/**
 * RouterOS client that retains the raw error body discarded by the vendor client.
 *
 * The installed ResponseParser turns RouterOS errors such as
 * `{"error":400,"message":"Bad Request","detail":"input does not match any value of interface"}`
 * into the unhelpful message "HTTP 400: 400". The vendor client still emits the
 * raw body through its debug hook, so we capture it here without changing vendor code.
 *
 * It also retains the local TCP endpoint used for the last request so RouterOS
 * connection tracking can identify the MMUTech server's own source address.
 */
class DetailedMikrotikClient extends Client
{
    private ?string $lastResponseBody = null;

    private ?int $lastLocalPort = null;

    private ?string $lastLocalIp = null;

    public function lastResponseBody(): ?string
    {
        return $this->lastResponseBody;
    }

    public function lastLocalPort(): ?int
    {
        return $this->lastLocalPort;
    }

    public function lastLocalIp(): ?string
    {
        return $this->lastLocalIp;
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

    /**
     * Mirror the vendor request flow while retaining the local cURL endpoint.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int|string, mixed>
     */
    protected function request(string $method, string $endpoint, array $payload = []): array
    {
        if (! $this->isConnected()) {
            throw MikrotikException::connectionFailed('unknown', 'Client is not connected. Call connect() first.');
        }

        $this->lastResponseBody = null;
        $this->lastLocalPort = null;
        $this->lastLocalIp = null;

        $url = "{$this->scheme}://{$this->host}:{$this->port}/".ltrim($endpoint, '/');

        $this->debug(">>> [REST] {$method} {$url}");

        $ch = curl_init();

        $headers = [
            'Accept: application/json',
        ];

        if (! empty($payload)) {
            $jsonPayload = json_encode($payload);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
            $headers[] = 'Content-Type: application/json';
            $this->debug(">>> [PAYLOAD] {$jsonPayload}");
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_USERPWD, "{$this->username}:{$this->password}");

        if (! $this->verifySsl) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        }

        $response = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $localPort = curl_getinfo($ch, CURLINFO_LOCAL_PORT);
        $localIp = curl_getinfo($ch, CURLINFO_LOCAL_IP);
        $error = curl_error($ch);

        if (is_int($localPort) && $localPort > 0) {
            $this->lastLocalPort = $localPort;
        }

        if (is_string($localIp) && $localIp !== '') {
            $this->lastLocalIp = $localIp;
        }

        curl_close($ch);

        if ($response === false) {
            throw MikrotikException::connectionFailed($this->host, $error);
        }

        $this->debug("<<< [REST RESPONSE {$statusCode}] {$response}");

        return ResponseParser::parse($response, $statusCode);
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
