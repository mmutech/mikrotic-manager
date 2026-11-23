# RouterOS Router Setup

This guide walks through connecting a RouterOS 7 device to MMUTech, from network
and certificate preparation through registration and the first health check.

## Before you start

You need:

- A RouterOS v7 router on a current stable release, with console, WinBox, or
  WebFig administrator access.
- The Laravel application installed, migrated, and reachable by the user
  registering the router.
- An active MMUTech account with the `super-admin` role and router management
  permission. The Routers pages also require an active application
  subscription.
- The private management IP or DNS name of the Laravel server, for RouterOS
  firewall rules.
- A management IP or private DNS name for the router, reachable from the
  Laravel application server.
- A TLS certificate for the router, issued by a CA trusted by the Laravel
  server.

The router must be reachable from the machine that runs the Laravel queue
worker, not just from an administrator's browser.

## 1. Find the private hostname or IP address

The application only accepts routers whose hostname resolves exclusively to an
approved private CIDR range. Public addresses and hosts that resolve outside
the allowlist are rejected.

Open RouterOS using WinBox, WebFig, or SSH, then list local addresses:

```text
/ip/address/print
```

Choose an address on a management or LAN interface that is reachable from the
Laravel application server. A typical private address is `192.168.88.1`. Do not
use a public address. Use a static address or DHCP reservation so the address
does not change unexpectedly.

A private DNS hostname is also supported:

```text
router-hq.example.internal
```

The hostname must resolve to an allowlisted address and must not resolve to a
public address or a mix of public and private addresses.

Create a DNS record if using a hostname. It must resolve from the Laravel
application server, and all returned addresses must be inside the configured
management CIDRs.

From the Laravel server, check DNS:

```powershell
Resolve-DnsName router-hq.example.internal
```

For an IP address, use that address in the subsequent steps instead.

## 2. Allow the application server to reach the router

The Laravel queue worker must be able to make outbound TCP connections to the
router's HTTPS port. Configure routing and firewalls on both sides:

1. Allow outbound TCP from the Laravel worker host or subnet to the router's
   management address and HTTPS port.
2. Allow inbound TCP to the RouterOS `www-ssl` service from the Laravel worker
   host or subnet.
3. Keep router management access limited to trusted administrator networks.

RouterOS REST is provided by the `www-ssl` web service. Its normal HTTPS port is
`443`. Port `8729` is normally RouterOS binary API-over-SSL, not REST; use it for
MMUTech only if `www-ssl` has explicitly been configured to listen on `8729`.
Do not expose the router REST service to the public internet.

## 3. Configure the Laravel application

In the application `.env`, set the actual router management networks. Use the
narrowest CIDRs that cover the router addresses; do not keep the broad example
ranges unless they are genuinely required:

```env
MIKROTIK_MANAGEMENT_NETWORKS="192.168.88.0/24,10.20.0.0/16"
ROUTER_ALLOW_HTTP=false
ROUTER_TLS_VERIFY=true
ROUTER_CONNECT_TIMEOUT=8
ROUTER_HEALTH_INTERVAL=5
ROUTER_OFFLINE_AFTER_FAILURES=3
ROUTER_API_LOG_RETENTION_DAYS=30
```

The host entered at registration must resolve exclusively to an address in one
of these CIDRs. The application checks this when saving the router and again
before connecting.

After changing `.env`, refresh Laravel's cached configuration:

```powershell
php artisan config:clear
php artisan config:cache
```

Keep HTTP disabled. The application uses HTTPS by default and production always
verifies the router's TLS certificate.

## 4. Create a dedicated RouterOS REST user

Do not use or share an administrator account. Create a dedicated user with
the `rest-api` login policy and the permissions needed for MMUTech's operations.

### WinBox or WebFig

1. Open **System > Users > Groups** and add a group named `mmutech-manager`.
2. Give the group the `read`, `write`, and `rest-api` policies.
3. Open **System > Users** and add a user such as `mmutech-api`.
4. Set a strong, unique password and assign the `mmutech-manager` group.
5. Save the user.

### RouterOS CLI

Run as an administrator. Replace the example password with a unique secret;
do not use this sample value:

```text
/user/group/add name=mmutech-manager policy=read,write,rest-api
/user/add name=mmutech-api group=mmutech-manager password="REPLACE_WITH_A_UNIQUE_PASSWORD"
```

The `rest-api` policy allows REST login; `read` allows read-only configuration
commands. MMUTech also needs `write` to set the RouterOS System Identity to the
registered router name; create bridges and add bridge ports; and, on explicit
request, configure LAN addressing, DHCP, NAT, and firewall rules. RouterOS
group policies are not a per-URL allowlist: `read` and especially `write` grant
access beyond the endpoints MMUTech uses. Do not grant `policy` or other
unnecessary permissions. If broad `write` access is not acceptable, do not use
these configuration features with this user.

The health probe reads:

```text
/rest/system/identity
/rest/system/resource
```

Router profile lookup also reads `/rest/ip/hotspot/profile` when that feature is
used.

Identity synchronization writes with `PUT /rest/system/identity` and payload
`{"name":"<registered router name>"}` after registration and when the name is
changed in MMUTech. Scheduled health probes remain read-only.

The router detail page can discover physical Ethernet interfaces and queue
creation or reuse of a named bridge plus the selected ports. It does not change
interface addresses, DHCP, firewall, or routing configuration. Adding a WAN or
management interface to a bridge can interrupt access, so inspect the selected
ports and confirm the warning before applying.

## 5. Create and install the router TLS certificate

The certificate must be valid for the exact hostname or IP entered in the
application. For a hostname, include it as a DNS Subject Alternative Name
(SAN); for an IP address, include it as an IP SAN. The Laravel server must
trust the certificate issuer.

### Recommended: use your organization's certificate authority

Have your internal CA issue a server certificate for the router's management
hostname or IP. The certificate must:

- Be valid for TLS server use.
- Include the exact router hostname in a DNS SAN, or the exact IP in an IP SAN.
- Be signed by a CA trusted by the Laravel server.

Generate the private key and CSR on the router when practical, so the private
key does not need to be transferred. In WinBox, use **System > Certificates**
to create the request with the correct common name and SAN. Submit the CSR to
your CA, then import the signed certificate and any required intermediate
certificates back into RouterOS. Keep the private key on the router.

### Small private deployment: create a local CA and server certificate

The following RouterOS CLI example creates a local CA and server certificate for
`router-hq.example.internal`. Replace the example hostname with the exact DNS
name entered in the application. For an IP-based registration, use that IP as
an `IP:` SAN instead of a `DNS:` SAN.

```text
/certificate/add name=mmutech-ca-template common-name=mmutech-router-ca key-usage=key-cert-sign,crl-sign
/certificate/sign mmutech-ca-template name=mmutech-router-ca
/certificate/add name=mmutech-server-template common-name=router-hq.example.internal subject-alt-name=DNS:router-hq.example.internal key-usage=tls-server
/certificate/sign mmutech-server-template ca=mmutech-router-ca name=mmutech-router-server
/ip/service/set www-ssl certificate=mmutech-router-server disabled=no
```

For an IP, the corresponding server-certificate template should use values like:

```text
/certificate/add name=mmutech-server-template common-name=192.168.88.1 subject-alt-name=IP:192.168.88.1 key-usage=tls-server
```

Export only the CA's public certificate and install that CA certificate in the
Laravel server's trusted certificate store:

```text
/certificate/export-certificate mmutech-router-ca
```

The export is available under **Files** on the router. Transfer the public CA
certificate securely to the Laravel server and add it to the trust store used
by PHP's TLS client. For example, on Debian/Ubuntu:

```bash
sudo install -m 0644 cert_export_mmutech-router-ca.crt /usr/local/share/ca-certificates/mmutech-router-ca.crt
sudo update-ca-certificates
```

On Windows, run an elevated PowerShell prompt:

```powershell
certutil.exe -addstore -f Root C:\certs\mmutech-router-ca.crt
```

If PHP/cURL uses a separate CA bundle, add the CA there as well and confirm the
queue worker's PHP runtime uses that bundle. Do not export or distribute the CA
private key. For multiple routers, prefer an organization-managed CA instead
of reusing a router-local CA.

Restrict the RouterOS HTTPS service to the application server's management IP
or subnet where possible, and use firewall rules to block untrusted sources.
Check the current service configuration with:

```text
/ip/service/print
```

When `ROUTER_TLS_VERIFY=true`, the Laravel server must trust the issuing CA and
the registered host must match the certificate SAN. Production always verifies
TLS certificates. The per-router setting can disable verification only outside
the production environment and only for a documented lab setup; do not use
that as the production certificate solution.

Confirm basic network connectivity from the Laravel server:

```powershell
Test-NetConnection router-hq.example.internal -Port 443
```

If you explicitly configured `www-ssl` on port `8729`, test that port:

```powershell
Test-NetConnection 192.168.88.1 -Port 8729
```

## 6. Test RouterOS REST before registering

From the Laravel application server, check the REST endpoint and credentials
with `curl`. `--cacert` points to the CA certificate if it is not already in
the system/PHP trust bundle. With no password provided in the command,
`curl` prompts for it:

```powershell
curl.exe --cacert C:\certs\mmutech-router-ca.crt --user mmutech-api https://router-hq.example.internal/rest/system/identity
curl.exe --cacert C:\certs\mmutech-router-ca.crt --user mmutech-api https://router-hq.example.internal/rest/system/resource
```

Both requests should return JSON without a certificate, connection, or
authentication error. Do not use `-k` / `--insecure` as a production fix; it
skips certificate verification.

## 7. Register the router

1. Open **Routers**.
2. Select **Register router**.
3. Enter:
   - **Router name**: The application display name.
   - **Site**: Optional physical or logical site name.
   - **Location detail**: Optional rack or room description.
   - **Hostname or private IP**: The address prepared in step 1.
   - **REST port**: Use `443` for the standard `www-ssl` service. Use `8729`
     only if you explicitly configured `www-ssl` on that port.
   - **RouterOS API user**: The dedicated username with `read`, `write`, and `rest-api` policies described above.
   - **RouterOS API password**: The password configured on RouterOS.
   - **TLS verification**: Keep enabled for a trusted certificate.
   - **Health monitoring**: Keep enabled for scheduled probes.
4. Save the router.

The router is stored in pending or disabled status, and the application queues
an identity synchronization and a read-only connection test. The API password
is encrypted at rest and is never displayed or returned in router views or
audit records. Identity synchronization failures appear in the router's job
history; a later name edit queues another attempt.

To configure a LAN bridge, open the router detail page, discover Ethernet
interfaces, choose only the intended LAN ports, review the WAN/management
warning, and queue the operation. Bridge results and errors appear in Router
jobs. The operation is safe to retry: it reuses an existing bridge and skips
ports already attached to it.

After the bridge is ready, use **DHCP, NAT & firewall** on the same page to
configure Internet sharing. Discover interfaces and select an unbridged WAN
Ethernet port that already has upstream IPv4 addressing and a default route;
MMUTech does not invent upstream settings. Enter the LAN gateway address and prefix, DHCP pool range,
upstream DNS server addresses, and management source CIDRs. These values are
router/site-specific and are deliberately not pre-filled. Include the
application server's actual source address or subnet in the management CIDRs;
otherwise the WAN input filter may cut off MMUTech's RouterOS API connection.
Management CIDRs must use a prefix from `/8` through `/32`; a broad
`0.0.0.0/0` management allowance is rejected.

The queued configuration adds the LAN IP address, DHCP pool/server/network,
LAN-to-WAN masquerade, and stateful firewall rules. Existing configuration is
preserved; conflicts stop the operation, and verification warns when existing
firewall or NAT rules precede the MMUTech rules and when existing WAN ACCEPT
rules may be shadowed. The WAN input drop may interrupt VPNs or other services
hosted on the router. The WAN forward drop applies only
to new, non-dstnat traffic so existing port-forwarded destinations are not
blocked by that rule. Confirm the warning before applying. **Verify
configuration** only reads RouterOS state; it does not test Internet
connectivity.

The registering user receives access automatically. A super administrator can
grant access to other staff from the router detail page.

Start a queue worker so the initial connection test can run:

```powershell
php artisan queue:work --tries=3 --timeout=90
```

For production, run the worker under Supervisor or the hosting platform's
process manager. The worker host must be able to reach the router and trust its
certificate.

## 8. Verify registration and enable ongoing monitoring

Open the router detail page. A successful probe updates:

- Router status
- Identity
- RouterOS version
- Board name
- Uptime
- CPU load
- Free and total memory
- Latency
- Last checked and last seen timestamps

Use **Test connection** to queue another read-only probe. If a test is already
queued or running, it must finish or fail before another test can start.

For scheduled health checks, keep monitoring enabled and configure the Laravel
scheduler to run every minute. The application schedules router health checks
every five minutes.

For local development, run:

```powershell
php artisan schedule:work
```

In production, add the scheduler to cron or the platform scheduler:

```cron
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

Use the **Test connection** action for an immediate manual test; the scheduler
is for recurring health checks.

### Setup completion checklist

- [ ] Router host resolves only to the configured private management CIDRs.
- [ ] RouterOS `www-ssl` is reachable from the Laravel queue worker.
- [ ] The dedicated API user can read and write as needed; its `write` policy
      grants broad RouterOS access beyond MMUTech's documented operations.
- [ ] The router's certificate SAN matches the registered host and its CA is
      trusted by the queue worker's PHP runtime.
- [ ] The router appears as **Online** after the connection test.
- [ ] Any configured LAN, DHCP, NAT, and firewall values match the site's
      addressing plan, and the read-back verification report has been reviewed.
- [ ] The queue worker and scheduler are running for background and recurring
      checks.

## Troubleshooting

### Host not in approved network

- Add the exact private CIDR range to `MIKROTIK_MANAGEMENT_NETWORKS`.
- Clear and recache the Laravel configuration.
- Verify the hostname resolves only to an address inside that range.

### Authentication failure

- Confirm the username and password on RouterOS.
- Confirm the user is active and its group has both `read` and `rest-api`
  policies.
- Use the credentials on the router, not an existing application login.
- Check for typos in the hostname, port, or password.

### TLS failure

- Confirm `www-ssl` is enabled.
- Confirm the Laravel server can reach the HTTPS port.
- Confirm the certificate is not expired and its SAN matches the registered
  hostname or IP.
- Confirm the issuing CA (including any intermediate CA) is trusted by PHP's
  TLS client on the Laravel server.
- Check that the selected port is serving the RouterOS REST web service, not
  only the binary API-SSL service.
- In production, do not disable TLS verification.

### Connection refused or timeout

- Confirm the router and Laravel server can route to each other.
- Confirm RouterOS firewall rules permit the application server.
- Confirm the REST service is enabled and listening on the selected port.
- Confirm `ROUTER_CONNECT_TIMEOUT` is adequate.

### Test remains queued

- Start the queue worker:

```powershell
php artisan queue:work
```

- Review job failures in the router detail page and Laravel logs.
- For production queues, restart and inspect the persistent worker process.

### Password rotation

Existing passwords cannot be read back. In **Edit router**, enter a new password
to rotate it, or use the credentials form on the router detail page. A successful
rotation queues a fresh connection test.

## Official RouterOS references

- [REST API](https://help.mikrotik.com/docs/spaces/ROS/pages/47579162/REST+API)
- [Certificates](https://help.mikrotik.com/docs/spaces/ROS/pages/2555969/Certificates)
- [IP Services](https://help.mikrotik.com/docs/spaces/ROS/pages/103841820/Services)
- [RouterOS Users and Groups](https://help.mikrotik.com/docs/spaces/ROS/pages/8978504/User)
