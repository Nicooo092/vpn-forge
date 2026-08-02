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

];
