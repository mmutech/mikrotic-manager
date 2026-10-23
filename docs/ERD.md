# ERD Overview

## Core Entities

### Users & RBAC
- `users`: name, email, phone, password (hashed), status, last_login_at, password_changed_at, must_change_password, soft deletes
- `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` (Spatie Permission)
- `customers`: user profile for customer portal

### Routers
- `routers`: public_id (ULID), name, location, site, host, rest_port, api_username, api_password (encrypted), tls_verify, status, monitoring_enabled, health metrics, metadata, created_by, soft deletes
- `router_user`: pivot for explicit access (granted_by, timestamps)
- `router_health_checks`: historical health probe results
- `router_jobs`: queued operations with status, attempts, correlation_id
- `api_logs`: read-only API call telemetry (redacted)

### Plans & Purchases
- `plans`: data bundle plans (belongs to router via nullable `router_id`, auto-generated `code`)
- `router_plan_profiles`: mapping between routers and hotspot profiles
- `purchases`: customer purchases
- `vouchers`: generated vouchers with lifecycle and provisioning status (separate)
- `voucher_usage`: usage tracking
- `transactions`: payment transactions
- `payment_webhook_events`: Paystack webhooks

### Billing
- `subscriptions`: user_id, optional router_id, plan, status (pending/active/expired/approved), starts_at, expires_at, amount, currency, metadata — required for `network-admin` access

### System
- `audit_logs`: immutable audit trail
- `settings`: application settings
- `notifications`: notifications
- `hotspot_sessions`: active hotspot sessions (separate from Laravel sessions)
- `cache`, `jobs`, `sessions`: framework tables

## Key Relationships
- User has many Customers, belongs to many Routers, has many Subscriptions
- Plan belongs to a Router (nullable); a Router has many Plans
- Router belongs to many Users, has many Vouchers/Purchases/Sessions/Jobs/HealthChecks/ApiLogs
- Voucher belongs to Router and Plan, has usage records
- Purchase belongs to User/Customer/Plan/Router/Transaction
- AuditLog is polymorphic (actor, auditable)

## Status Enums
- UserStatus: Active, Suspended, Pending
- RouterStatus: Pending, Online, Offline, Disabled, Error, Archived
- ProvisioningStatus: Pending, Provisioning, Provisioned, Failed
- VoucherLifecycleStatus: Pending, Active, Used, Expired, Revoked
- RouterJobStatus: Queued, Running, Succeeded, Failed, Retrying, Cancelled
- HealthCheckStatus: Online, Offline, AuthenticationFailed, TlsError, Timeout, Error
- RouterErrorCategory: Dns, Timeout, ConnectionRefused, Tls, Authentication, Authorization, Http, InvalidResponse, Configuration, Unknown
