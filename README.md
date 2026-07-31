# vpn-forge

A self-hosted panel for managing your own WireGuard and OpenVPN servers: multiple
independent services, per-service users, detailed connection/bandwidth stats, and
visibility into DNS-level domains visited and full plaintext HTTP traffic.

This is a **personal-use tool**: it's built for monitoring your own devices on VPN
services you control, not for reselling or sharing access with other people without
their knowledge. See [Privacy notes](#privacy-notes) below before turning on logging.

## Quick start

Run as root on a fresh **Ubuntu 24.04 LTS** server:

```bash
curl -fsSL https://raw.githubusercontent.com/Nicooo092/vpn-forge/main/install.sh -o /tmp/vpnforge-install.sh && sudo bash /tmp/vpnforge-install.sh
```

You'll be asked for a domain (or IP), an admin account, and whether to enable
Let's Encrypt SSL. Everything else -- nginx, MariaDB, PHP, WireGuard, OpenVPN,
the privileged worker, the capture agent -- is installed and started automatically.

Only Ubuntu 24.04 is supported for now (see [Scope](#scope) below for why).

## Table of contents

- [What this does](#what-this-does)
- [Architecture](#architecture)
- [Ports to open](#ports-to-open)
- [Manual installation](#manual-installation)
- [Troubleshooting](#troubleshooting)
- [Privacy notes](#privacy-notes)
- [Scope](#scope)
- [License](#license)

## What this does

- **Services**: create as many independent WireGuard or OpenVPN instances as you
  want, each with its own interface, port, and subnet.
- **Users per service**: add/revoke users from the panel; each gets a single
  downloadable config file (`.conf` or `.ovpn`).
- **Logging**: a per-service default (on/off), overridable per user. Covers
  connection metadata, DNS-visible domains (works for HTTPS sites too, since it's
  based on the DNS query, not decrypted traffic), and full content of any plaintext
  HTTP requests.
- **Stats**: bandwidth-over-time charts, connection history, live status.
- **Logs are capped at 90 days** and can be exported (as a zip of CSVs) at any time,
  per-service or in full, before they age out.

## Architecture

Three pieces run on one server:

1. **The panel** (Laravel + Filament, `www-data`, unprivileged) -- the web UI. It
   never touches the network stack directly.
2. **A privileged queue worker** (`vpnforge-worker`, a dedicated non-root system
   user holding only `CAP_NET_ADMIN` via systemd `AmbientCapabilities=`) -- the
   only process that writes WireGuard/OpenVPN config, calls `wg`/`easyrsa`, and
   manages the NAT rules. The web process only ever enqueues jobs for it.
3. **A capture agent** (a small Go binary, `vpnforge-agent`, granted
   `cap_net_raw`/`cap_net_admin` via `setcap` -- not root) -- watches each
   service's dnsmasq log for DNS queries and sniffs each service's tunnel
   interface for plaintext HTTP, writing rows straight into the same database the
   panel reads from, using its own separate, minimally-privileged database user.

Every protocol-specific piece (WireGuard, OpenVPN) sits behind one shared driver
interface, so another protocol could be added later without touching the panel
or job layer.

## Ports to open

| Port | Protocol | What |
|---|---|---|
| 22 | TCP | SSH (already there, listed for completeness) |
| 80 | TCP | The panel over HTTP, or transiently for Let's Encrypt if you use HTTPS |
| 443 | TCP | The panel over HTTPS (only if you enabled SSL) |
| *(per service)* | UDP or TCP | Whatever port you pick when creating each VPN service -- shown in the panel, and reminded there every time |

**Not exposed, and should never be opened externally:** each service's dnsmasq
instance (bound only to that service's internal tunnel address), the capture
agent (purely passive, no listener at all), and MariaDB (bound to localhost only).

Since services are created *after* installation, from inside the panel, this
installer has no way to know their ports in advance -- the panel reminds you of
the port to open every time you create one.

## Manual installation

If you'd rather run each step yourself instead of the one-line installer, here's
everything it does, in order, for **Ubuntu 24.04 LTS**:

### 1. Packages

```bash
apt-get update
apt-get install -y curl wget git unzip tar nginx mariadb-server \
  php8.3-cli php8.3-fpm php8.3-gd php8.3-mysql php8.3-mbstring php8.3-bcmath \
  php8.3-xml php8.3-curl php8.3-zip php8.3-intl php8.3-sqlite3 \
  wireguard wireguard-tools openvpn easy-rsa dnsmasq iptables \
  golang-go gcc libpcap-dev

curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

systemctl enable --now mariadb nginx php8.3-fpm
systemctl disable --now dnsmasq  # only per-service instances are used, not the stock one

echo 'net.ipv4.ip_forward=1' > /etc/sysctl.d/99-vpnforge.conf
sysctl -p /etc/sysctl.d/99-vpnforge.conf
```

### 2. Database

Pick a strong password for each of these, then:

```sql
CREATE DATABASE vpnforge;
CREATE USER 'vpnforge'@'127.0.0.1' IDENTIFIED BY 'your-app-db-password';
GRANT ALL PRIVILEGES ON vpnforge.* TO 'vpnforge'@'127.0.0.1';

-- A second, much more restricted user for the capture agent -- it should
-- never be able to touch anything but traffic_logs and read-only lookups.
CREATE USER 'vpnforge_agent'@'127.0.0.1' IDENTIFIED BY 'your-agent-db-password';
FLUSH PRIVILEGES;
```

(The grants on `traffic_logs`/`services`/`service_users` for `vpnforge_agent` are
applied in step 3, after migrations create those tables.)

### 3. The application

```bash
git clone https://github.com/Nicooo092/vpn-forge.git /var/www/vpnforge
cd /var/www/vpnforge
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction
cp .env.example .env
php artisan key:generate --force -n
```

Edit `.env` and set (or use `php artisan config:clear` after):

```
APP_URL=https://your-domain-or-ip
APP_TIMEZONE=Your/Timezone
DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=vpnforge
DB_USERNAME=vpnforge
DB_PASSWORD=your-app-db-password
CACHE_STORE=database
SESSION_DRIVER=database
QUEUE_CONNECTION=database
```

Then:

```bash
php artisan migrate --force

mariadb -e "GRANT INSERT, SELECT ON vpnforge.traffic_logs TO 'vpnforge_agent'@'127.0.0.1';"
mariadb -e "GRANT SELECT ON vpnforge.services TO 'vpnforge_agent'@'127.0.0.1';"
mariadb -e "GRANT SELECT ON vpnforge.service_users TO 'vpnforge_agent'@'127.0.0.1';"
mariadb -e "FLUSH PRIVILEGES;"

php artisan make:filament-user --name=admin --email=you@example.com --password='a-strong-password'

chmod -R 755 storage/* bootstrap/cache/
chown -R www-data:www-data /var/www/vpnforge

# The scheduler needs to run every minute as www-data:
( crontab -u www-data -l 2>/dev/null | grep -v 'schedule:run'; \
  echo '* * * * * php /var/www/vpnforge/artisan schedule:run >> /dev/null 2>&1' ) | crontab -u www-data -
```

### 4. nginx (+ SSL)

Use `installer/lib/webserver_setup.sh` in this repo as the exact vhost template
(substitute your domain/IP and `/var/www/vpnforge`), then:

```bash
ln -sf /etc/nginx/sites-available/vpnforge.conf /etc/nginx/sites-enabled/vpnforge.conf
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

# Only if you have a real domain (not a bare IP):
apt-get install -y certbot python3-certbot-nginx
certbot --nginx -d your-domain --agree-tos -m you@example.com --redirect
```

### 5. Privileged worker

```bash
useradd --system --no-create-home --shell /usr/sbin/nologin vpnforge-worker

mkdir -p /etc/wireguard /etc/openvpn/vpnforge /etc/openvpn/server /etc/vpnforge/dnsmasq /var/log/vpnforge

# /etc/wireguard and /etc/openvpn/vpnforge hold real secrets; /etc/openvpn/server
# is where Ubuntu's packaged openvpn-server@.service hardcodes its
# WorkingDirectory + a relative --config, so server.conf has to live there too.
# 770 (not 750): the worker doesn't just read these, it creates files/
# subdirectories in them, which needs the group write bit. Setgid so anything
# created inside later keeps the right group regardless of which identity
# creates it.
chgrp -R vpnforge-worker /etc/wireguard /etc/openvpn/vpnforge
chmod -R 770 /etc/wireguard /etc/openvpn/vpnforge
chmod g+s /etc/wireguard /etc/openvpn/vpnforge
chgrp vpnforge-worker /etc/openvpn/server /etc/vpnforge/dnsmasq
chmod 770 /etc/openvpn/server /etc/vpnforge/dnsmasq
chmod g+s /etc/openvpn/server /etc/vpnforge/dnsmasq

# /etc/vpnforge itself and /var/log/vpnforge stay 755 (not 770): vpnforge-agent
# (a separate, unrelated group) needs to traverse both to reach agent.yml and
# each service's DNS log file -- a directory's own permissions gate traversal
# into it regardless of what's inside, so the worker keeps write access via the
# group while "other" still gets read+traverse.
chmod 755 /etc/vpnforge /var/log/vpnforge
chgrp vpnforge-worker /var/log/vpnforge
```

`systemctl enable`/`disable`/`restart` on the WireGuard/OpenVPN/dnsmasq systemd
units goes over D-Bus to systemd, which polkit -- not `CAP_NET_ADMIN` -- gates;
without a rule granting it, every provisioning job fails with "Interactive
authentication required" (and `NoNewPrivileges=yes` below rules out `sudo` as a
fix, since sudo itself needs a new-privilege execve to elevate):

```bash
mkdir -p /etc/polkit-1/rules.d
cp installer/templates/polkit/vpnforge-worker.rules /etc/polkit-1/rules.d/49-vpnforge-worker.rules
```

Copy `installer/templates/systemd/vpnforge-worker.service.tpl` to
`/etc/systemd/system/vpnforge-worker.service` (substitute `/var/www/vpnforge` for
`__APP_DIR__`), then:

```bash
systemctl daemon-reload
systemctl enable --now vpnforge-worker
```

### 6. Capture agent

```bash
cd /var/www/vpnforge/agent
go build -o /usr/local/bin/vpnforge-agent .
setcap cap_net_raw,cap_net_admin=eip /usr/local/bin/vpnforge-agent

useradd --system --no-create-home --shell /usr/sbin/nologin vpnforge-agent
# Not chgrp'd to vpnforge-agent -- /var/log/vpnforge is already 755 (see
# step 5), which is enough for this separate, unrelated user to traverse it
# and open each service's DNS log file (each already 640 to vpnforge-agent
# specifically, via that unit's ExecStartPost).

cat > /etc/vpnforge/agent.yml <<EOF
database:
  host: 127.0.0.1
  port: 3306
  user: vpnforge_agent
  password: your-agent-db-password
  database: vpnforge
poll_interval_seconds: 30
dns_log_dir: /var/log/vpnforge
EOF
chown root:vpnforge-agent /etc/vpnforge/agent.yml
chmod 640 /etc/vpnforge/agent.yml
```

Copy `installer/templates/systemd/vpnforge-dnsmasq@.service.tpl` to
`/etc/systemd/system/vpnforge-dnsmasq@.service` and
`installer/templates/systemd/vpnforge-agent.service.tpl` to
`/etc/systemd/system/vpnforge-agent.service`, then:

```bash
systemctl daemon-reload
systemctl enable --now vpnforge-agent
```

(The per-service `vpnforge-dnsmasq@<interface>` instances are started
automatically by the panel when you create a Service -- nothing more to do here.)

### 7. First login

Visit your panel URL, log in with the admin account you created, and create your
first Service. The port it needs is shown right there in the form.

## Troubleshooting

**A Service is stuck "Provisioning" or shows an error, and nothing seems to
happen.** Job dispatch (fast, from the web request) is decoupled from execution
(async, on the worker). Check the worker is actually running:
`systemctl status vpnforge-worker`. If it's not, `journalctl -u vpnforge-worker
-n 50` will show why.

**A service provisions but no internet flows through the tunnel.** Almost always
one of: `sysctl net.ipv4.ip_forward` isn't `1`, or the NAT rule is using the wrong
egress interface (check with `ip route show default` -- it isn't always `eth0`;
edit the Service's Advanced tab if so).

**Two services won't both start.** Their ports collide. WireGuard and OpenVPN (UDP)
share one port namespace at the OS level -- the panel validates this, but if you
edited the database directly, check with `ss -ulnp` / `ss -tlnp`.

**nginx returns a 502.** PHP-FPM's socket path can differ across setups; check
`ls /run/php/` matches what's in the nginx vhost (`php8.3-fpm.sock`).

**WireGuard interface won't come up.** Some minimal/OpenVZ-style VPS hosts can't
load the WireGuard kernel module at all. This installer assumes a KVM or
bare-metal host.

**No DNS logs are showing up for a service, ever.** Either the client is using
its own hardcoded DNS resolver instead of the one pushed to it, or is using
DNS-over-HTTPS/TLS -- both are silent, expected gaps (see
[Privacy notes](#privacy-notes)). Also check the per-service dnsmasq instance is
actually running: `systemctl status vpnforge-dnsmasq@<interface>`.

**No HTTP logs are showing up, ever.** Check `setcap -v cap_net_raw,cap_net_admin=eip
/usr/local/bin/vpnforge-agent` reports the capability is actually set (some
filesystems strip extended attributes, silently dropping it), and check
`journalctl -u vpnforge-agent` for errors opening the interface.

**A revoked OpenVPN user stays connected for a while.** Expected: the certificate
revocation list is only re-checked on new connections or TLS renegotiation
(up to an hour later by default), not continuously. This isn't a bug.

**Logs seem to be missing / arriving at the wrong time.** Check both the panel's
server and the capture agent agree on the clock: `timedatectl` should show NTP
synchronized. The two write to the same tables from different processes, and
correlate by timestamp.

## Privacy notes

This panel can log, per user (on by default per-service, overridable per user):

- Connection metadata (when, from where, how much data).
- Every domain visited, including over HTTPS -- this uses DNS query visibility,
  not decryption, so it's silently blind to clients using DNS-over-HTTPS/TLS or a
  hardcoded external resolver.
- The **full content** (headers and body) of any plaintext (non-HTTPS) HTTP
  traffic. If a site you visit isn't using HTTPS, whatever you send it --
  including passwords, if that site is careless enough to accept them in
  plaintext -- is logged in full.

There is no TLS interception/MITM here, and none is planned: it would need a
certificate installed on every client device, breaks apps that use certificate
pinning, and turns a personal monitoring tool into something that looks a lot
like traffic interception if ever pointed at anyone other than your own devices.
Point this at infrastructure and devices you own, not at other people's traffic,
and turn per-user logging off for anyone (or anything) where you don't want it.

## Scope

This is a personal project, not a hosting platform. Deliberately out of scope for
now: any OS other than Ubuntu 24.04 LTS, a self-service portal for VPN users
(the panel is admin-only), running Panel and VPN services across multiple
machines, and an uninstaller.

## License

MIT.
