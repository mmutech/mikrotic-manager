# Limitations & Known Issues

## Current Feature Gaps
- **Customer Portal**: The `customer` role/permission exists, but there is no separate self-service portal UI. Customer records are managed by staff.
- **Notifications**: The notifications table exists, but no email/SMS delivery pipeline is implemented. Renewals and payment events are not pushed to users.
- **Refunds**: `transactions.refund` permission exists; no refund workflow is implemented. Refunds must be handled directly in Paystack.
- **Paystack Recurrence**: Subscriptions are activated manually by a super-admin. There is no Paystack plan/subscription auto-renewal or card mandate.
- **Reports Export**: Reports render on-screen with scoped metrics; CSV/PDF export is not implemented.
- **Voucher Printing**: `vouchers.print` permission exists but a print/batch template is not yet implemented.
- **Router Sessions**: `sessions.view`/`sessions.disconnect` permissions exist; live hotspot session management UI is not implemented.

## Subscription Model Limitations
- Only the `network-admin` role is gated by subscriptions. Support, finance, and customer roles are not gated.
- A network admin without an active subscription can still reach `/subscription` and account security pages; all operational routes redirect to `/subscription`.
- Renewals are request-and-approve only: a network admin submits a pending request, and a super-admin activates it. There is no payment-backed self-service activation.
- Activating while a subscription is still valid extends from the current expiry; only one subscription is `active` at a time (others are marked `expired`/`approved`).

## Technical Limitations
- **DNS Rebinding**: `RouterHostPolicy` validates at request time but DNS can change between validation and connection. Production must enforce egress filtering at network layer.
- **HTTP Port 80**: Disabled by default for security. Only enable with explicit `ROUTER_ALLOW_HTTP=true` and understanding of risks.
- **Single Connection**: Each probe creates a new connection; no connection pooling.
- **Queue Dependency**: Health monitoring, provisioning, and revocation require queue workers running.
- **RouterOS Variance**: Hotspot user provisioning depends on RouterOS 7 API behavior and per-router hotspot profile configuration; profile mapping is not fully automated.
- **Test Coverage**: 43 tests cover policies, auth, router access, plan/router scoping, provisioning jobs, and subscriptions. Broader end-to-end/UI coverage is still needed.

## Security Considerations
- **Password Storage**: Router passwords encrypted but if APP_KEY is compromised, they can be decrypted.
- **Super-Admin Powers**: Super-admins have broad access (except credential readback).
- **Audit Log Integrity**: Audit logs stored in database; consider write-only storage for high security.
- **API Rate Limiting**: Basic rate limiting on login/password reset; may need adjustment.
- **Webhook Verification**: Paystack webhooks verify HMAC-SHA512 using `PAYSTACK_WEBHOOK_SECRET` (falling back to the secret key). If no secret is configured, verification is skipped — configure it in production.

## Browser Support
- Modern browsers with ES6 support
- Tested with current Chrome/Firefox/Edge

## Recommendations for Production
1. Implement network-level egress filtering to router management IPs
2. Use Redis for queues/cache/sessions
3. Configure `PAYSTACK_WEBHOOK_SECRET` and restrict webhook source IPs where supported
4. Set up monitoring/alerting and log aggregation
5. Implement a real payment-backed subscription renewal flow
6. Add customer portal and notification delivery
7. Regular security audits, database backups, and WAF protection
8. Implement 2FA for admin accounts (future enhancement)
