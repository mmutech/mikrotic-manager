<?php

namespace App\Services;

use App\Exceptions\RouterProbeException;
use App\Models\Router;
use App\Support\RouterHostPolicy;
use Illuminate\Support\Facades\Log;
use Mivo\LaravelMikrotikRos7\MikrotikManager;
use Mivo\MikrotikRos7\Client;

class RouterConnectionFactory
{
    public function __construct(
        private readonly MikrotikManager $manager,
        private readonly RouterHostPolicy $hostPolicy,
    ) {}

    /**
     * Build a short-lived, package-native REST client for one router.
     *
     * This method never returns credentials to a view or API response.
     */
    public function make(Router $router): Client
    {
        if ($router->trashed()) {
            throw RouterProbeException::configuration('Archived routers cannot be contacted.');
        }

        if (! in_array($router->rest_port, (array) config('mikrotik.allowed_ports', [443, 8729]), true)) {
            throw RouterProbeException::configuration(
                'The router REST port is not an approved RouterOS endpoint.'
            );
        }

        try {
            $this->hostPolicy->assertAllowed($router->host);
        } catch (\RuntimeException $exception) {
            throw RouterProbeException::configuration($exception->getMessage());
        }

        $isTls = in_array($router->rest_port, [443, 8729], true);

        if (! $isTls && ! config('mikrotik.allow_http', false)) {
            throw RouterProbeException::configuration(
                'Plain HTTP router access is disabled. Use HTTPS on port 443 or 8729.'
            );
        }

        $verifyTls = $isTls && $this->shouldVerifyTls($router);

        if ($isTls && ! $verifyTls) {
            Log::warning('Router TLS verification is disabled for a non-production environment.', [
                'router_id' => $router->getKey(),
                'router_public_id' => $router->public_id,
            ]);
        }

        return $this->manager->connection([
            'host' => $router->host,
            'username' => $router->api_username,
            'password' => $router->api_password,
            'port' => (int) $router->rest_port,
            'verify_ssl' => $verifyTls,
            'timeout' => (int) config('mikrotik.connect_timeout', 8),
            'debug' => false,
        ]);
    }

    private function shouldVerifyTls(Router $router): bool
    {
        if (app()->environment('production')) {
            return true;
        }

        return (bool) $router->tls_verify;
    }
}
