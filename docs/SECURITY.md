# Security Guide

## Authentication & Authorization
- All routes under `auth` middleware require authentication; `active` middleware ensures account is active
- RBAC via Spatie Permission with role-based permissions
- Policies enforce authorization on every sensitive action
- Super-admins bypass most permissions (except `viewCredentials` which is always denied)
- Router access requires explicit assignment in `router_user` pivot (defense in depth)

## Router Security
- **Credentials**: API passwords encrypted with Laravel `encrypted` cast, hidden from arrays/JSON, never displayed
- **Rotation**: Only credential rotation allowed; no readback mechanism exists
- **SSRF Protection**: `RouterHostPolicy` validates hosts resolve to approved CIDR ranges (configurable via `MIKROTIK_MANAGEMENT_NETWORKS`)
- **Port Restrictions**: Only ports 443, 8729 allowed by default; HTTP (80) disabled unless `ROUTER_ALLOW_HTTP=true`
- **TLS**: Verification enforced in production; can be disabled per-router only in non-production for lab certs
- **Read-Only Operations**: All probes use read-only endpoints (`/rest/system/identity`, `/rest/system/resource`, `/rest/ip/hotspot/profile`)

## Audit & Logging
- All sensitive actions logged via `AuditLogger` with redaction
- Sensitive keys (password, token, secret, key, etc.) automatically redacted
- Auth headers and credentials in strings scrubbed
- Router passwords never included in audit payloads
- API logs record endpoint/method/status/latency only, no request/response bodies
- Correlation IDs track requests across jobs

## Network Security
- Security headers middleware adds X-Content-Type-Options, X-Frame-Options, CSP-related headers, HSTS (on HTTPS)
- Correlation ID middleware for request tracing
- Trusted proxies configurable
- **Important**: DNS rebinding is a residual risk - production must restrict outbound traffic at network/firewall level

## Best Practices
- Use strong passwords (12+ chars, mixed case, numbers) for admin accounts
- Restrict `MIKROTIK_MANAGEMENT_NETWORKS` to private management/VPN ranges
- Run queue workers for health checks and jobs
- Monitor audit logs regularly
- Keep dependencies updated
- Use HTTPS in production
- Never commit `.env` files or credentials
