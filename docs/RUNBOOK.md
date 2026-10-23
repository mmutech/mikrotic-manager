# Runbook

## Daily Operations

### Monitor Routers
- Check dashboard for routers needing attention
- Review failed health checks
- Monitor queue jobs

### User Management
- Create users via tinker or admin interface (when implemented)
- Assign roles and router access
- Reset passwords as needed

## Common Tasks

### Queue Worker Management
```bash
# Start worker
php artisan queue:work --queue=default --tries=3

# Restart workers (after deployment)
php artisan queue:restart
```

### Clear Caches
```bash
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan permission:cache-reset
```

### Router Maintenance
- **Test connection**: Use router detail page or dispatch job
- **Rotate credentials**: Use edit page or rotate form (queues test)
- **Disable monitoring**: Toggle on router detail page
- **Archive router**: Super-admin only, moves to archived status

## Troubleshooting

### Router shows Offline
1. Check router is powered on and network reachable
2. Verify host resolves to IP in allowlist
3. Test credentials manually
4. Check firewall rules
5. Review API logs and health check history
6. Queue a manual test

### Jobs stuck in Queued
1. Ensure queue worker is running
2. Check `jobs` table for failed jobs
3. Review logs in `storage/logs/laravel.log`
4. Restart queue worker

### Permission denied errors
1. Verify user has correct role
2. Check router access in `router_user` table
3. Clear permission cache
4. Review policy logic

### Authentication issues
1. Check user status is Active
2. Verify password requirements met
3. Check if account locked (rate limiting)
4. Review audit logs

## Emergency Procedures

### Disable a router immediately
1. Go to router detail page
2. Toggle monitoring off, or archive if compromised
3. Revoke access if needed

### Revoke all access
Use `router_user` pivot management via super-admin interface

### Audit investigation
Check `audit_logs` table filtered by correlation_id, user, or action
