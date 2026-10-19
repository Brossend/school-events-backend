<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'health', 'up'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000,http://127.0.0.1:3000,http://localhost:8000,http://127.0.0.1:8000')))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Trace-Id', 'X-School-Id'],

    'max_age' => 0,

    'supports_credentials' => env('CORS_SUPPORTS_CREDENTIALS', true),

];
