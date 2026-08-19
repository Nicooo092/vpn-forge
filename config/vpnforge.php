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
    | (they are walked through enrolment on their next login; it is not a
    | lockout). On by default -- this panel can read everyone's browsing and its
    | backups hold every key on the box. Set VPNFORGE_REQUIRE_MFA=false only if
    | you have a deliberate reason to run the panel with a password alone.
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
    ],

    /*
    |--------------------------------------------------------------------------
    | Expiry alerting
    |--------------------------------------------------------------------------
    |
    | How many days ahead of a user's expires_at to raise a one-off operator
    | "expiring soon" alert (de-duplicated per user per expiry). Set to 0 to
    | disable expiry warnings entirely. Operator-side only.
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

];
