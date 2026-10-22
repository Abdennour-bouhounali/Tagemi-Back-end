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
    // Comma-separated in CORS_ALLOWED_ORIGINS; set it in the local .env to
    // allow http://localhost:5173 during development.
    'allowed_origins' => array_filter(array_map('trim', explode(',', env(
        'CORS_ALLOWED_ORIGINS',
        'https://tagemi-foundation.org,https://www.tagemi-foundation.org'
    )))),

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