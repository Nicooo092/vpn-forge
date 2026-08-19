# Security policy

## Reporting a vulnerability

Please report security issues **privately**, not in a public issue. Use GitHub's
[private vulnerability reporting](https://github.com/Nicooo092/vpn-forge/security/advisories/new)
for this repository, or open a minimal public issue asking for a private contact
channel without disclosing details.

Please include what it affects, how to reproduce it, and the impact. There is no
bug-bounty programme; this is a self-hosted project maintained on a best-effort
basis.

## Scope

vpn-forge is designed around a deliberate privilege split:

- **Panel** (`www-data`) — internet-facing, holds no network privileges.
- **Worker** (`vpnforge-worker`) — `CAP_NET_ADMIN` only, the sole writer to the
  network stack.
- **Agent** (`vpnforge-agent`) — `cap_net_raw`, passive capture, no listening
  socket.

Reports that demonstrate crossing one of these boundaries — e.g. reaching the
worker's privileges from the panel — are especially valuable.

## Deploying safely

- **Put the panel behind HTTPS on a real domain.** It holds session cookies and
  its backups hold every private key on the box. Restrict *who* can reach the
  panel as well — your cloud security group, a reverse proxy that only accepts
  your CDN's ranges (vpn-forge already trusts Cloudflare's proxy so it sees the
  real client IP; see `bootstrap/app.php`), or the panel's built-in IP allowlist.
- **Enable two-factor authentication** for every panel account.
- **Keep backups off the public web.** An archive is a plaintext vault of keys
  and `APP_KEY`; encrypt any offsite copy.
- **Never expose** MariaDB, the per-service dnsmasq instances, or the capture
  agent — none should be reachable from outside the host.
- **Keep the OS patched**, and reboot when the kernel asks.

## Supported versions

Only the latest `main` is supported. There are no long-term support branches.
