<?php

return [
    /*
     * Paths that should have CORS enabled
     */
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    /*
     * Allowed HTTP methods
     */
    'allowed_methods' => ['*'],

    /*
     * Allowed origins - IMPORTANT: Add your production domains
     */
    'allowed_origins' => [
        'https://tagemi-foundation.org',
        'https://www.tagemi-foundation.org',
        'http://localhost:3000',
        'http://localhost:5173',
    ],

    /*
     * Patterns for allowed origins (alternative to listing each domain)
     */
    'allowed_origins_patterns' => [],

    /*
     * Allowed headers
     */
    'allowed_headers' => ['*'],

    /*
     * Headers to expose
     */
    'exposed_headers' => [],

    /*
     * Maximum cache time for preflight requests
     */
    'max_age' => 86400, // 24 hours for production

    /*
     * Allow credentials (cookies, authorization headers)
     */
    'supports_credentials' => true,
];