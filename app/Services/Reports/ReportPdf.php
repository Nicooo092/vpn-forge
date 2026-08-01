<?php

namespace App\Services\Reports;

use App\Models\Service;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;

/**
 * Renders the "sites visités" report for one service and window to PDF bytes,
 * off the same ServiceReportData the on-screen report uses.
 */
class ReportPdf
{
    public function render(Service $service, Carbon $since, string $periodLabel): string
    {
        $data = new ServiceReportData($service, $since);

        return Pdf::loadView('reports.service-pdf', [
            'service' => $service,
            'periodLabel' => $periodLabel,
            'generatedAt' => now(),
            'hasData' => $data->hasData(),
            'totalLookups' => $data->totalLookups(),
            'distinctDomains' => $data->distinctDomains(),
            'breakdown' => $data->categoryBreakdown(),
            'topDomains' => $data->topDomains(50),
            'topUsers' => $data->topUsers(15),
        ])->output();
    }
}
