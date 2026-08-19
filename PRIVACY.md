# Privacy notice

vpn-forge is self-hosted software. There is no vendor, no telemetry and no
phone-home: **everything it records stays on the server you run it on, and you
— the operator — are the sole data controller.** This document describes what
the software is capable of recording so that you can meet your own obligations
to the people who use your VPN. It is not legal advice.

## What can be recorded

Recording is configurable per service and per user, shown plainly in the panel,
and **on by default for a new service**. When enabled for a user, vpn-forge can
store:

- **Connection metadata** — when a device connected and disconnected, the source
  IP it connected from, and how much data it moved.
- **DNS lookups** — every domain name a device resolved through the service's
  resolver, including for HTTPS sites, with the resolver queried, the answers
  returned, the CNAME chain and whether the answer was cached. This is DNS-level
  visibility, **not decryption**: it sees the name looked up, never the content
  of the connection, and it is blind to a client using DNS-over-HTTPS or a
  hardcoded external resolver.
- **Plaintext HTTP requests** — for traffic to non-HTTPS (port 80) sites only,
  the method, host, path and request body (capped at 16 KB). Credential-bearing
  headers (`Authorization`, `Cookie`, and the like) are **redacted to a
  length-only placeholder before storage** and are never written in the clear.
  HTTPS traffic is never inspected.

There is **no TLS interception** in vpn-forge, and none is planned.

## What is never recorded

Private keys and other secrets are never written to the activity logs. The
change history excludes the telemetry the poller writes on its own. HTTPS
payloads are never seen.

## Retention

Connection, DNS and HTTP logs are kept for **90 days** and pruned daily. You can
export them (as a ZIP of CSVs) or delete a service's data at any time from the
panel. Backups are a separate matter — see below.

## If you run this for other people

If anyone other than you uses a VPN service you operate, then in most
jurisdictions (including under the EU/UK GDPR) you are processing their personal
data, and responsibilities follow. At a minimum you should:

1. **Tell them** what is recorded, why, and for how long — before they use it.
2. **Turn logging off** for anyone it should not cover; it is a per-user switch,
   and the change history records who turned it on.
3. **Have a lawful basis** for any logging you keep on, and collect no more than
   you need.
4. **Honour access and erasure requests** — the export and per-service delete
   tools exist for this.
5. **Protect the data at rest.** A backup archive contains the database and
   **every private key on the box, plus `APP_KEY`, in the clear.** Treat it like
   the key material it is: keep it off the public web, encrypt any offsite copy,
   and delete local copies once moved.

Point vpn-forge at devices and infrastructure you are authorised to monitor.
Recording other people's browsing without a lawful basis and, where required,
their knowledge, may be unlawful where you are.

## Contact

vpn-forge has no operator of its own. The data controller for any given
installation is whoever runs it. If you are an end user, contact the person or
organisation that gave you your VPN configuration.
