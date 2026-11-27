<?php

namespace App\Support;

final class Roles
{
    public const SUPER_ADMIN = 'super-admin';

    public const NETWORK_ADMIN = 'network-admin';

    public const SALES_AGENT = 'sales-agent';

    /** @var array<int, string> */
    public const SUBSCRIBERS = [
        self::NETWORK_ADMIN,
        self::SALES_AGENT,
    ];
}
