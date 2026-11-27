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

        'network_admin_name' => env('MMUTECH_NETWORK_ADMIN_NAME', 'Network Admin'),
        'network_admin_email' => env('MMUTECH_NETWORK_ADMIN_EMAIL', 'network@mmutech.com'),
        'network_admin_password' => env('MMUTECH_NETWORK_ADMIN_PASSWORD', 'password'),

        'sales_agent_name' => env('MMUTECH_SALES_AGENT_NAME', 'Sales Agent Demo'),
        'sales_agent_email' => env('MMUTECH_SALES_AGENT_EMAIL', 'sales@mmutech.com'),
        'sales_agent_password' => env('MMUTECH_SALES_AGENT_PASSWORD', 'password'),

        'subscriber_subscription_type' => env('MMUTECH_SEED_SUBSCRIPTION_TYPE', 'monthly'),
    ],

    'subscription' => [
        'currency' => env('MMUTECH_SUBSCRIPTION_CURRENCY', 'NGN'),
        'prices' => [
            'monthly' => env('MMUTECH_SUBSCRIPTION_MONTHLY_PRICE', 0),
            'yearly' => env('MMUTECH_SUBSCRIPTION_YEARLY_PRICE', 0),
        ],
    ],
];
