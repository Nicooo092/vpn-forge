<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Scheduled exports
    |--------------------------------------------------------------------------
    |
    | The panel can generate a "sites visités" PDF for each active service on a
    | schedule and keep it under storage/app/exports for download from the
    | Exports page. Set the cadence to 'off' to disable it entirely.
    |
    */

    'exports' => [
        // off | daily | weekly | monthly
        'schedule' => env('VPNFORGE_EXPORT_SCHEDULE', 'weekly'),

        // The window each generated report covers, in days.
        'days' => (int) env('VPNFORGE_EXPORT_DAYS', 7),

        // Generated exports older than this are pruned on each run.
        'retention_days' => (int) env('VPNFORGE_EXPORT_RETENTION_DAYS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Update check
    |--------------------------------------------------------------------------
    |
    | The Updates page compares this install against the latest published
    | commit. Leave repo null to derive it from the git checkout's own origin
    | remote (so a fork checks itself).
    |
    */

    'update' => [
        'repo' => env('VPNFORGE_REPO'),        // e.g. "owner/name"; null = auto
        'branch' => env('VPNFORGE_BRANCH', 'main'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-user traffic shaping
    |--------------------------------------------------------------------------
    |
    | The ceiling (in Mbit/s) used for traffic that is NOT capped -- the top of
    | the HTB tree. Set it to this server's real uplink so unshaped users are
    | not needlessly limited and shaped users are enforced against a truthful
    | link speed.
    |
    */

    'shaping' => [
        'link_mbit' => (int) env('VPNFORGE_LINK_MBIT', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    |
    | require_mfa forces every panel account to set up two-factor authentication
    | -- a passkey (WebAuthn) or an authenticator-app code -- on their next login
    | (walked through enrolment, not locked out). On by default: this panel can
    | read everyone's browsing and its backups hold every key on the box. Set
    | VPNFORGE_REQUIRE_MFA=false only if you have a deliberate reason to run the
    | panel with a password alone (e.g. briefly, while first enrolling a passkey).
    |
    */

    'security' => [
        'require_mfa' => (bool) env('VPNFORGE_REQUIRE_MFA', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backups
    |--------------------------------------------------------------------------
    |
    | Automatic backups run on the chosen cadence and keep the newest `keep`
    | archives, pruning the rest. If `offsite_disk` names a configured
    | filesystem disk (e.g. the S3-compatible 's3' disk in config/filesystems.php
    | -- works with AWS, Backblaze B2, Wasabi, MinIO), each new archive is also
    | uploaded there.
    |
    */

    'backup' => [
        // off | daily | weekly
        'schedule' => env('VPNFORGE_BACKUP_SCHEDULE', 'off'),

        // How many local archives to keep before pruning the oldest.
        'keep' => (int) env('VPNFORGE_BACKUP_KEEP', 7),

        // A filesystem disk name to also upload to, or null for local only.
        'offsite_disk' => env('VPNFORGE_BACKUP_OFFSITE_DISK'),

        // A passphrase to encrypt each finished archive at rest with
        // `openssl enc -aes-256-cbc -pbkdf2`. Empty (the default) keeps the
        // plaintext .zip -- backward compatible. It lives ONLY here / in .env
        // and is never written into the archive: lose it and an encrypted
        // backup is unrecoverable.
        'passphrase' => env('VPNFORGE_BACKUP_PASSPHRASE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Expiry alerting
    |--------------------------------------------------------------------------
    |
    | How many days ahead of a user's expires_at to raise a one-off operator
    | "expiring soon" alert (de-duplicated per user per expiry). Set to 0 to
    | disable expiry warnings entirely -- this one window governs BOTH the
    | operator alert and the end-user "your access is about to expire" email, so
    | 0 turns off both.
    |
    */

    'expiry' => [
        'warn_days' => (int) env('VPNFORGE_EXPIRY_WARN_DAYS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | External heartbeat
    |--------------------------------------------------------------------------
    |
    | A dead man's switch. When `url` is set the scheduler (www-data, not the
    | worker) pings it on a schedule -- e.g. a healthchecks.io or Uptime Kuma
    | push URL -- so a fully-dead box, where the worker and thus every internal
    | alert are down, is still detected off-host. Empty disables it. The URL is
    | the only secret involved. `include_status` appends a compact, user-free
    | status query (active/total services, worker heartbeat age) to each ping.
    |
    */

    'heartbeat' => [
        'url' => env('VPNFORGE_HEARTBEAT_URL'),
        'include_status' => (bool) env('VPNFORGE_HEARTBEAT_STATUS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | DNS blocking dashboard
    |--------------------------------------------------------------------------
    |
    | The blocking dashboard rolls up DNS traffic_logs over a 24h/7d window --
    | range scans over a large, insert-heavy table -- so results are cached for
    | `cache_ttl` seconds; the numbers barely move in between. `top_limit` caps
    | how many blocked domains the top-blocked table lists.
    |
    */

    'blocking' => [
        'cache_ttl' => (int) env('VPNFORGE_BLOCKING_CACHE_TTL', 60),
        'top_limit' => (int) env('VPNFORGE_BLOCKING_TOP_LIMIT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Prometheus metrics
    |--------------------------------------------------------------------------
    |
    | A read-only /metrics endpoint (Prometheus format) for an external scraper.
    | It lives outside the panel's login, so the bearer token below is its only
    | guard: set VPNFORGE_METRICS_TOKEN to a long random string. Empty -> the
    | endpoint returns 404 (off until deliberately enabled). Output is counts and
    | per-service up/down only; no keys, addresses or user data.
    |
    */

    'metrics' => [
        'token' => env('VPNFORGE_METRICS_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | End-user email notifications
    |--------------------------------------------------------------------------
    |
    | Emails an end user (not the operator) about their OWN account: access about
    | to expire, or a connection from a network not seen for them before. Only
    | sent when the user has an email set AND a real mailer is configured
    | (MAIL_MAILER other than 'log'); degrades silently otherwise. No browsing or
    | traffic detail is ever emailed. Set to false to turn the channel off.
    |
    */

    'user_mail' => [
        'enabled' => (bool) env('VPNFORGE_USER_MAIL_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | GeoIP enrichment (optional)
    |--------------------------------------------------------------------------
    |
    | Connection logs can be annotated with the country and ASN of the client's
    | source IP, using the free DB-IP Lite databases (CC-BY, MaxMind mmdb
    | format). The installer downloads them to /etc/vpnforge/geoip and the
    | scheduler refreshes them monthly. Entirely optional: with a database file
    | absent the GeoLocator degrades to null and the country column stays empty.
    |
    */

    'geoip' => [
        'country_db' => env('VPNFORGE_GEOIP_COUNTRY_DB', '/etc/vpnforge/geoip/dbip-country-lite.mmdb'),
        'asn_db' => env('VPNFORGE_GEOIP_ASN_DB', '/etc/vpnforge/geoip/dbip-asn-lite.mmdb'),

        // monthly | off
        'refresh' => env('VPNFORGE_GEOIP_REFRESH', 'monthly'),

        // Where the free, month-stamped mmdb.gz files are published.
        'download_base' => env('VPNFORGE_GEOIP_DOWNLOAD_BASE', 'https://download.db-ip.com/free'),
    ],

    /*
    |--------------------------------------------------------------------------
    | CSV user import
    |--------------------------------------------------------------------------
    |
    | The service users table's "Import CSV" action bulk-creates users. This caps
    | how many rows one import reads, so a huge or accidental paste cannot enqueue
    | an unbounded number of provisioning jobs; rows beyond the cap are reported
    | as skipped. Set to 0 for no cap.
    |
    */

    'imports' => [
        'max_rows' => (int) env('VPNFORGE_IMPORT_MAX_ROWS', 1000),
    ],

];
