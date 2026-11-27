<?php

return [
    'enabled' => env('PULSE_ENABLED', env('PULSESERVER_ENABLED', true)),
    'api_base' => env('PULSE_URL', env('PULSESERVER_URL', 'https://pulse.bifrost.co.mz')),
    'key' => env('PULSE_KEY', env('PULSESERVER_KEY')),
    'public_key' => env('PULSE_PUBLIC_KEY'),
    'environment' => env('PULSE_ENVIRONMENT', env('PULSESERVER_ENVIRONMENT', env('APP_ENV', 'production'))),
    'release' => env('PULSE_RELEASE', env('PULSESERVER_RELEASE')),
    'transport' => env('PULSE_TRANSPORT', env('PULSESERVER_QUEUE_CONNECTION') ? 'queue' : 'http'),
    'connection' => env('PULSE_QUEUE_CONNECTION', env('PULSESERVER_QUEUE_CONNECTION', 'database')),
    'queue' => env('PULSE_QUEUE', env('PULSESERVER_QUEUE', 'default')),
    'timeout' => env('PULSE_TIMEOUT', 2),
    'widget' => env('PULSE_WIDGET_ENABLED', true),
    'browser_errors' => env('PULSE_BROWSER_ERRORS_ENABLED', true),
    'nonce' => null,
    'send_identity' => false,
];
