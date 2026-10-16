<?php

return [
    'enabled' => env('ROUTEROS_ENABLED', false),
    'url' => env('ROUTEROS_URL'),
    'username' => env('ROUTEROS_USERNAME'),
    'password' => env('ROUTEROS_PASSWORD'),
    'interface' => env('ROUTEROS_WG_INTERFACE', 'wg-vpnrent'),
    'public_key' => env('ROUTEROS_WG_PUBLIC_KEY'),
    // Explicitly reserved /24 for this application; .1 is the gateway.
    'pool_prefix' => env('ROUTEROS_POOL_PREFIX'),
    'capacity' => (int) env('ROUTEROS_CAPACITY', 100),
];
