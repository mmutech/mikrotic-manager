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
- **Router Operations**: Health probes use read-only endpoints (`/rest/system/identity`, `/rest/system/resource`, `/rest/ip/hotspot/profile`). Registration/name changes write the configured name to `/rest/system/identity`. Authorized administrators can create a bridge, add explicitly selected Ethernet ports, and configure LAN addressing, DHCP, NAT, and firewall rules. These writes require RouterOS `write` permission, which grants broader configuration access than these operations alone. Bridge and network configuration require separate impact confirmations. The network firewall permits new management connections from supplied CIDRs, accepts established traffic, drops other WAN input, and drops new non-dstnat WAN forwarding. An incorrect management CIDR or WAN selection can interrupt connectivity; verify values and review read-back warnings before applying.

## Audit & Logging
- All sensitive actions logged via `AuditLogger` with redaction
- Sensitive keys (password, token, secret, key, etc.) automatically redacted
- Auth headers and credentials in strings scrubbed
- Router passwords never included in audit payloads
- API logs record endpoint/method/status/latency only, no request/response bodies
- Correlation IDs track requests across jobs
- Network configuration inputs and read-back reports are stored with tracked router jobs; they contain site IP/CIDR data and must be treated as operationally sensitive.

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
