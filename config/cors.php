<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'getProfile'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://127.0.0.1:5500',
        'https://nso.organizemypeople.com',
        'https://www.nso.organizemypeople.com',
        'https://portal.organizationstaff.org',
        'https://www.portal.organizationstaff.org',
        env('APP_URL')
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'], // this is fine (allows Authorization)

    'exposed_headers' => ['Authorization'], // Add Authorization here so frontend can read it if needed

    'max_age' => 0,

    'supports_credentials' => false,
];
