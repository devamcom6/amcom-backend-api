<?php

/*
| CORS (contract §1.11): only the approved frontend origins (FRONTEND_URLS, comma-separated).
| Never use a wildcard in production — Authorization headers are involved.
*/

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map(
        fn ($origin) => rtrim(trim($origin), '/'),
        explode(',', (string) env('FRONTEND_URLS', env('FRONTEND_URL', 'http://localhost:5173'))),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'X-Requested-With', 'X-XSRF-TOKEN'],

    'exposed_headers' => ['Retry-After', 'Content-Disposition'],

    'max_age' => 3600,

    // Bearer tokens don't need credentials; enable only for Sanctum cookie mode (handoff §7).
    'supports_credentials' => (bool) env('CORS_SUPPORTS_CREDENTIALS', false),

];
