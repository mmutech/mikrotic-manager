# MMUTech Documentation

## Overview
MMUTech is a production-ready Laravel 12/13 + Livewire 4 + Tailwind CSS v4 system for managing MikroTik RouterOS 7 routers and data-bundle operations. It provides secure router registration, read-only health monitoring, voucher/purchase scaffolding, RBAC, auditing, and Paystack integration hooks.

## Architecture
- **Backend**: Laravel (PHP 8.5), Eloquent models, policies, jobs, services
- **Frontend**: Livewire 4 single-file components in `resources/views/pages/` with `pages::` namespace, layouts under `resources/views/layouts/`
- **Database**: MySQL 9.7
- **Queue/Cache/Session**: Database drivers locally; Redis (predis) ready for production
- **Router Integration**: `mivodev/laravel-mikrotik-api-ros7` v0.2.1, read-only probes via `SystemManager` and REST endpoints
- **Auth/RBAC**: Spatie Permission, roles (super-admin, network-admin, support, finance, customer), policies enforce permissions
- **Security**: Host allowlist/SSRF protection, encrypted router passwords, redaction, audit logging, correlation IDs, security headers

## Features (Phase 1)
- **Authentication**: Login, forgot/reset password, forced password change, account security page, session management
- **Router Registry**: Register/edit/archive routers with SSRF-protected hosts, TLS/HTTP controls, credential rotation (no password readback), monitoring toggle
- **Health Monitoring**: Queued read-only connection tests (`TestRouterConnectionJob`), scheduled `SyncRouterHealthJob`, health history, API call logs
- **Access Control**: Router visibility scoped via `router_user` pivot; super-admin only can grant/revoke access; creator auto-attached
- **Audit Trail**: Immutable audit logs with redaction, filters, detail modal
- **Dashboard**: Real scoped metrics (no fake data)

## Quick Start
```bash
# Install dependencies
composer install
npm install

# Configure environment
cp .env.example .env
php artisan key:generate

# Setup database (MySQL on 127.0.0.1:3307 as configured)
php artisan migrate --seed

# Build assets
npm run build

# Run tests
php artisan test
```

## Environment Variables (Key)
- `DB_*`: MySQL connection
- `MIKROTIK_MANAGEMENT_NETWORKS`: Comma-separated CIDR allowlist for router hosts
- `ROUTER_ALLOW_HTTP`: Enable HTTP on port 80 (default false)
- `ROUTER_TLS_VERIFY`: Enforce TLS verification
- `MMUTECH_ADMIN_*`: Bootstrap admin credentials
- `PAYSTACK_*`: Paystack configuration
- `QUEUE_CONNECTION`, `CACHE_STORE`, `SESSION_DRIVER`: Queue/cache/session drivers

## Security Notes
- Router passwords stored encrypted (`encrypted` cast), hidden from serialization, never returned via API/views
- `viewCredentials` policy always denies; only rotation allowed
- Host validation via `RouterHostPolicy` + `SafeRouterHost` rule; DNS rebinding risk exists - restrict outbound traffic at network layer
- Audit logs redact sensitive keys; API logs never store request/response bodies
- Super-admin bypasses most permissions except credential read
- All sensitive actions logged with correlation IDs
