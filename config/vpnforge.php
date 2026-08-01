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

];
