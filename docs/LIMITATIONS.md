# Limitations & Known Issues

## Phase 1 Limitations
- **Plans/Vouchers/Purchases**: Database schema and models exist but UI and provisioning logic not fully implemented
- **Paystack Integration**: Configuration present but payment flows not implemented
- **Customer Portal**: Routes/models exist but customer-facing UI not implemented
- **Notifications**: Table exists but notification system not implemented
- **Reports**: Permissions exist but reporting features not implemented

## Technical Limitations
- **DNS Rebinding**: `RouterHostPolicy` validates at request time but DNS can change between validation and connection. Production must enforce egress filtering at network layer.
- **HTTP Port 80**: Disabled by default for security. Only enable with explicit `ROUTER_ALLOW_HTTP=true` and understanding of risks.
- **Single Connection**: Each probe creates a new connection; no connection pooling
- **Queue Dependency**: Health monitoring requires queue workers running
- **Test Coverage**: Basic tests implemented; more comprehensive tests needed for full coverage
- **Livewire v4**: Using SFC syntax; ensure compatibility

## Security Considerations
- **Password Storage**: Router passwords encrypted but if APP_KEY is compromised, they can be decrypted
- **Super-Admin Powers**: Super-admins have broad access (except credential readback)
- **Audit Log Integrity**: Audit logs stored in database; consider write-only storage for high security
- **API Rate Limiting**: Basic rate limiting on login/password reset; may need adjustment

## Browser Support
- Modern browsers with ES6 support
- Tested with current Chrome/Firefox/Edge

## Recommendations for Production
1. Implement network-level egress filtering to router management IPs
2. Use Redis for queues/cache/sessions
3. Set up proper monitoring and alerting
4. Implement log aggregation
5. Regular security audits
6. Backup strategy for database
7. Consider WAF protection
8. Implement 2FA for admin accounts (future enhancement)
