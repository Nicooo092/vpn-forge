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

];
