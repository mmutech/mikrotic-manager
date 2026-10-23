<?php

namespace App\Data;

final readonly class RouterProbeResult
{
    /**
     * @param  array<string, mixed>  $resources
     */
    public function __construct(
        public string $identity,
        public ?string $version,
        public ?string $boardName,
        public ?int $uptimeSeconds,
        public ?int $cpuLoad,
        public ?int $freeMemory,
        public ?int $totalMemory,
        public int $latencyMs,
        public array $resources,
    ) {}
}
