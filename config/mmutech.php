<?php

return [
    'brand' => [
        'name' => 'MMUTech',
        'product' => 'Router & Data Bundle Management',
        'support_email' => env('MMUTECH_SUPPORT_EMAIL', 'support@mmutech.local'),
    ],

    'seed' => [
        'admin_name' => env('MMUTECH_ADMIN_NAME', 'MMUTech Administrator'),
        'admin_email' => env('MMUTECH_ADMIN_EMAIL', 'admin@mmutech.local'),
        'admin_password' => env('MMUTECH_ADMIN_PASSWORD'),
    ],
];
