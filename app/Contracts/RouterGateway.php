<?php

namespace App\Contracts;

use App\Data\RouterProbeResult;
use App\Models\Router;
use App\Exceptions\RouterProbeException;

interface RouterGateway
{
    /**
     * Read identity and system resources. Read-only; never changes router state.
     *
     * @throws RouterProbeException
     */
    public function probe(Router $router, string $correlationId): RouterProbeResult;

    /**
     * Read the configured Hotspot server profiles.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws RouterProbeException
     */
    public function hotspotProfiles(Router $router, string $correlationId): array;
}
