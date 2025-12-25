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
    ]
];
