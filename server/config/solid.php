<?php

/**
 * -------------------------------------------
 * Fleetbase Core API Configuration
 * -------------------------------------------
 */
return [
    'api' => [
        'version' => '0.0.1',
        'routing' => [
            'prefix' => 'solid',
            'internal_prefix' => 'int'
        ],
    ],
    'server' => [
        'host' => env('SOLID_HOST', 'http://solid'),
        'port' => (int) env('SOLID_PORT', 3000),
        'secure' => (bool) env('SOLID_SECURE', false)
    ],
    // OIDC issuer URL - use HTTPS URL that goes through nginx for OIDC discovery
    // while server.host can use HTTP for direct API calls
    'oidc_issuer' => env('SOLID_OIDC_ISSUER', null)
];
