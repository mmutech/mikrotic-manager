<?php

$networks = env(
    'MIKROTIK_MANAGEMENT_NETWORKS',
    '127.0.0.0/8,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16'
);

return [
    /*
    |--------------------------------------------------------------------------
    | Approved management networks
    |--------------------------------------------------------------------------
    |
    | Router hosts must resolve exclusively to one of these CIDR ranges.
    | Restrict this list to your private management/VPN ranges in production.
    |
    */
    'management_networks' => array_values(array_filter(array_map('trim', explode(',', (string) $networks)))),

    /*
    |--------------------------------------------------------------------------
    | Supported REST endpoints
    |--------------------------------------------------------------------------
    |
    | The installed Mivo client selects HTTPS for 443/8729 and HTTP for every
    | other port. We only permit those verified endpoints rather than allowing
    | arbitrary schemes or ports. HTTP is disabled unless explicitly enabled.
    |
    */
    'allowed_ports' => [80, 443, 8729],

    'allow_http' => filter_var(env('ROUTER_ALLOW_HTTP', false), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | TLS verification
    |--------------------------------------------------------------------------
    |
    | A router may opt out only outside production and only for a documented
    | lab certificate. Production enforces verification regardless of the
    | per-router column.
    |
    */
    'tls_verify' => filter_var(env('ROUTER_TLS_VERIFY', true), FILTER_VALIDATE_BOOL),

    'connect_timeout' => (int) env('ROUTER_CONNECT_TIMEOUT', 8),

    'health_interval_minutes' => (int) env('ROUTER_HEALTH_INTERVAL', 5),

    'offline_after_failures' => (int) env('ROUTER_OFFLINE_AFTER_FAILURES', 3),

    'api_log_retention_days' => (int) env('ROUTER_API_LOG_RETENTION_DAYS', 30),
];
