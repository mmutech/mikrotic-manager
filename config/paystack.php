<?php

return [
    'public_key' => env('PAYSTACK_PUBLIC_KEY'),
    'secret_key' => env('PAYSTACK_SECRET_KEY'),
    'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
    'webhook_tolerance' => (int) env('PAYSTACK_WEBHOOK_TOLERANCE', 300),
];
