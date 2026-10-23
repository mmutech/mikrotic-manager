# Deployment Guide

## Requirements
- PHP 8.2+ (tested on 8.5)
- Composer
- Node.js & NPM
- MySQL 9.7+
- Redis (recommended for production queues/cache)
- Web server (Nginx/Apache)

## Production Setup

### 1. Environment
```bash
cp .env.example .env
php artisan key:generate
```

Configure:
- `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://your-domain.com`
- Database credentials
- `QUEUE_CONNECTION=redis` (or database with workers)
- `CACHE_STORE=redis`
- `SESSION_DRIVER=redis`
- `MIKROTIK_MANAGEMENT_NETWORKS` - restrict to your management network
- `ROUTER_ALLOW_HTTP=false`
- `ROUTER_TLS_VERIFY=true`
- Paystack keys if using payments

### 2. Install Dependencies
```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
```

### 3. Database
```bash
php artisan migrate --force
php artisan db:seed --force  # Sets up roles and admin user
```

### 4. Optimization
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

### 5. Queue Workers
```bash
php artisan queue:work --tries=3 --timeout=90
```

Or use Supervisor to manage workers.

### 6. Scheduler
Add to crontab:
```cron
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

## Security Checklist
- [ ] HTTPS enabled with valid certificate
- [ ] `APP_DEBUG=false`
- [ ] Restrict `MIKROTIK_MANAGEMENT_NETWORKS` to private ranges
- [ ] Firewall rules limit outbound connections to router management IPs
- [ ] Queue workers running
- [ ] Scheduler configured
- [ ] Strong admin password set
- [ ] Regular backups configured
- [ ] Audit logs monitored

## Troubleshooting
- **Router connection fails**: Check host is in allowlist, DNS resolves to private IP, port/firewall rules
- **Jobs not processing**: Ensure queue worker is running
- **Permission errors**: Clear permission cache with `php artisan permission:cache-reset`
- **Build issues**: Run `npm run build` after CSS/JS changes
