<?php

namespace App\Support;

final class Permissions
{
    public const DASHBOARD_VIEW = 'dashboard.view';

    public const ROUTERS_VIEW = 'routers.view';

    public const ROUTERS_MANAGE = 'routers.manage';

    public const ROUTERS_TEST = 'routers.test';

    public const PLANS_VIEW = 'plans.view';

    public const PLANS_MANAGE = 'plans.manage';

    public const VOUCHERS_VIEW = 'vouchers.view';

    public const VOUCHERS_GENERATE = 'vouchers.generate';

    public const VOUCHERS_PROVISION = 'vouchers.provision';

    public const VOUCHERS_REVOKE = 'vouchers.revoke';

    public const VOUCHERS_PRINT = 'vouchers.print';

    public const CUSTOMERS_VIEW = 'customers.view';

    public const CUSTOMERS_MANAGE = 'customers.manage';

    public const SESSIONS_VIEW = 'sessions.view';

    public const SESSIONS_DISCONNECT = 'sessions.disconnect';

    public const TRANSACTIONS_VIEW = 'transactions.view';

    public const TRANSACTIONS_VERIFY = 'transactions.verify';

    public const TRANSACTIONS_REFUND = 'transactions.refund';

    public const REPORTS_VIEW = 'reports.view';

    public const REPORTS_EXPORT = 'reports.export';

    public const AUDIT_VIEW = 'audit.view';

    public const USERS_MANAGE = 'users.manage';

    public const SETTINGS_MANAGE = 'settings.manage';

    public const SUPPORT_VIEW = 'support.view';

    public const SUPPORT_RESPOND = 'support.respond';

    public const CUSTOMER_PORTAL = 'customer.portal';

    /** @return array<int, string> */
    public static function all(): array
    {
        return [
            self::DASHBOARD_VIEW,
            self::ROUTERS_VIEW,
            self::ROUTERS_MANAGE,
            self::ROUTERS_TEST,
            self::PLANS_VIEW,
            self::PLANS_MANAGE,
            self::VOUCHERS_VIEW,
            self::VOUCHERS_GENERATE,
            self::VOUCHERS_PROVISION,
            self::VOUCHERS_REVOKE,
            self::VOUCHERS_PRINT,
            self::CUSTOMERS_VIEW,
            self::CUSTOMERS_MANAGE,
            self::SESSIONS_VIEW,
            self::SESSIONS_DISCONNECT,
            self::TRANSACTIONS_VIEW,
            self::TRANSACTIONS_VERIFY,
            self::TRANSACTIONS_REFUND,
            self::REPORTS_VIEW,
            self::REPORTS_EXPORT,
            self::AUDIT_VIEW,
            self::USERS_MANAGE,
            self::SETTINGS_MANAGE,
            self::SUPPORT_VIEW,
            self::SUPPORT_RESPOND,
            self::CUSTOMER_PORTAL,
        ];
    }

    /** @return array<string, array<int, string>> */
    public static function roleMap(): array
    {
        return [
            'network-admin' => [
                self::DASHBOARD_VIEW,
                self::ROUTERS_VIEW,
                self::ROUTERS_MANAGE,
                self::ROUTERS_TEST,
                self::PLANS_VIEW,
                self::PLANS_MANAGE,
                self::VOUCHERS_VIEW,
                self::VOUCHERS_GENERATE,
                self::VOUCHERS_PROVISION,
                self::VOUCHERS_REVOKE,
                self::VOUCHERS_PRINT,
                self::SESSIONS_VIEW,
                self::SESSIONS_DISCONNECT,
                self::REPORTS_VIEW,
                self::CUSTOMERS_VIEW,
            ],
            'support' => [
                self::DASHBOARD_VIEW,
                self::CUSTOMERS_VIEW,
                self::CUSTOMERS_MANAGE,
                self::VOUCHERS_VIEW,
                self::SESSIONS_VIEW,
                self::TRANSACTIONS_VIEW,
                self::SUPPORT_VIEW,
                self::SUPPORT_RESPOND,
            ],
            'finance' => [
                self::DASHBOARD_VIEW,
                self::TRANSACTIONS_VIEW,
                self::TRANSACTIONS_VERIFY,
                self::TRANSACTIONS_REFUND,
                self::REPORTS_VIEW,
                self::REPORTS_EXPORT,
            ],
            'customer' => [
                self::CUSTOMER_PORTAL,
            ],
        ];
    }
}
