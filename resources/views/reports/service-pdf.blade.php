<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    {{-- dompdf only understands a subset of CSS 2.1 -- no flexbox or grid, so
         the whole thing is laid out with plain tables and the default DejaVu
         Sans font (which carries the accents and box characters). Kept
         deliberately plain: a printable summary, not a redesign. --}}
    <style>
        @page { margin: 32px 40px; }
        body { font-family: "DejaVu Sans", sans-serif; color: #1f2937; font-size: 11px; line-height: 1.45; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        h2 { font-size: 12px; margin: 22px 0 6px; color: #374151; border-bottom: 1px solid #d1d5db; padding-bottom: 3px; }
        .muted { color: #6b7280; }
        .header { border-bottom: 2px solid #111827; padding-bottom: 8px; margin-bottom: 4px; }
        .brand { font-size: 10px; letter-spacing: 0.04em; text-transform: uppercase; color: #6b7280; margin: 0 0 4px; }
        .meta td { padding: 1px 0; font-size: 10.5px; }
        .meta td.k { color: #6b7280; width: 120px; }
        .summary { margin: 12px 0 0; }
        .summary td { padding: 8px 12px; border: 1px solid #e5e7eb; }
        .summary .n { font-size: 17px; font-weight: bold; }
        table.data { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.data th { text-align: left; font-size: 9.5px; text-transform: uppercase; letter-spacing: 0.03em; color: #6b7280; border-bottom: 1px solid #d1d5db; padding: 5px 6px; }
        table.data td { padding: 4px 6px; border-bottom: 1px solid #f3f4f6; }
        table.data td.num { text-align: right; white-space: nowrap; }
        .cat { color: #4b5563; font-size: 10px; }
        .bar-cell { width: 120px; }
        .bar { height: 7px; background: #e5e7eb; border-radius: 3px; }
        .bar > span { display: block; height: 7px; background: #4b5563; border-radius: 3px; }
        .empty { margin-top: 20px; color: #6b7280; }
        .foot { margin-top: 26px; padding-top: 6px; border-top: 1px solid #e5e7eb; font-size: 9px; color: #9ca3af; }
    </style>
</head>
<body>
    <div class="header">
        <p class="brand">vpn-forge &middot; sites report</p>
        <h1>{{ $service->name }}</h1>
        <table class="meta">
            <tr><td class="k">Service</td><td>{{ $service->name }} ({{ $service->interface_name }})</td></tr>
            <tr><td class="k">Period</td><td>{{ $periodLabel }}</td></tr>
            <tr><td class="k">Generated</td><td>{{ $generatedAt->toDayDateTimeString() }} UTC</td></tr>
        </table>
    </div>

    @unless ($hasData)
        <p class="empty">No DNS activity was recorded in this window. Domains appear once a user with logging
            enabled connects and their device uses this service's resolver.</p>
    @else
        <table class="summary" width="100%">
            <tr>
                <td width="50%"><div class="n">{{ number_format($totalLookups) }}</div><div class="muted">DNS lookups</div></td>
                <td width="50%"><div class="n">{{ number_format($distinctDomains) }}</div><div class="muted">distinct domains</div></td>
            </tr>
        </table>

        <h2>By category</h2>
        <table class="data">
            <thead>
                <tr><th>Category</th><th></th><th>Domains</th><th class="num">Lookups</th><th class="num">Share</th></tr>
            </thead>
            <tbody>
                @foreach ($breakdown as $row)
                    <tr>
                        <td>{{ $row['category']->getLabel() }}</td>
                        <td class="bar-cell"><div class="bar"><span style="width: {{ max(2, $row['percent']) }}%"></span></div></td>
                        <td>{{ number_format($row['domains']) }}</td>
                        <td class="num">{{ number_format($row['hits']) }}</td>
                        <td class="num">{{ $row['percent'] }}%</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <h2>Most visited domains</h2>
        <table class="data">
            <thead>
                <tr><th>Domain</th><th>Category</th><th class="num">Lookups</th></tr>
            </thead>
            <tbody>
                @foreach ($topDomains as $d)
                    <tr>
                        <td>{{ $d->host }}</td>
                        <td class="cat">{{ $d->category->getLabel() }}</td>
                        <td class="num">{{ number_format($d->hits) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if ($topUsers !== [])
            <h2>Most active users</h2>
            <table class="data">
                <thead>
                    <tr><th>User</th><th class="num">Lookups</th></tr>
                </thead>
                <tbody>
                    @foreach ($topUsers as $u)
                        <tr>
                            <td>{{ $u['name'] }}</td>
                            <td class="num">{{ number_format($u['hits']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endunless

    <p class="foot">Generated by vpn-forge. DNS lookups are only recorded for users with logging enabled whose
        devices use this service's resolver.</p>
</body>
</html>
