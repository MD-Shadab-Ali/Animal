<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | The Next.js storefront runs on a different origin from this API, so it
    | needs to be allowed explicitly. Add your production domain to
    | FRONTEND_URL (comma separated for more than one) rather than
    | widening this to "*".
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'storage/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(
        array_map('trim', explode(',', (string) env('FRONTEND_URL', 'http://localhost:3000')))
    ),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
