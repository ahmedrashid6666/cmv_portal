<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #10222f; }
    .head { border-bottom: 3px solid #1b9a9b; padding-bottom: 8px; margin-bottom: 14px; }
    .company { font-size: 18px; font-weight: bold; color: #1e3a5f; }
    .title { font-size: 13px; color: #158a8b; margin-top: 2px; }
    table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    th { background: #1e3a5f; color: #fff; text-align: left; padding: 6px; font-size: 10px; }
    td { padding: 5px 6px; border-bottom: 1px solid #e2e8f0; }
    tr:nth-child(even) td { background: #f5f7fb; }
    .totals { margin-top: 12px; }
    .totals span { display: inline-block; margin-right: 18px; font-weight: bold; color: #1e3a5f; }
    .num { text-align: right; }
    table.meta { width: 100%; margin-top: 0; }
    table.meta td { border-bottom: none; padding: 2px 6px 2px 0; font-size: 10px; }
    table.meta tr:nth-child(even) td { background: transparent; }
    table.meta .k { color: #64748b; width: 12%; }
    table.meta .v { color: #10222f; font-weight: bold; width: 38%; }
</style>
</head>
<body>
    <div class="head">
        <div class="company">{{ $company['name'] }}</div>
        <div class="title">{{ $report['title'] }}</div>
        <div style="font-size:10px;color:#64748b;margin-top:2px;">Generated {{ now()->format('d-m-Y h:i A') }}</div>
    </div>

    @if (!empty($report['meta']))
        <table class="meta">
            @foreach (array_chunk($report['meta'], 2, true) as $pair)
                <tr>
                    @foreach ($pair as $label => $value)
                        <td class="k">{{ $label }}</td><td class="v">{{ $value }}</td>
                    @endforeach
                </tr>
            @endforeach
        </table>
    @endif

    <table>
        <thead>
            <tr>@foreach ($report['columns'] as $col)<th>{{ $col }}</th>@endforeach</tr>
        </thead>
        <tbody>
            @php $numeric = $report['numericColumns'] ?? []; @endphp
            @forelse ($report['rows'] as $row)
                <tr>@foreach ($row as $i => $cell)
                    @if (in_array($i, $numeric, true))
                        <td class="num">{{ number_format((float) $cell, 2) }}</td>
                    @else
                        <td>{{ $cell }}</td>
                    @endif
                @endforeach</tr>
            @empty
                <tr><td colspan="{{ count($report['columns']) }}" style="text-align:center;color:#94a3b8;">No data.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="totals">
        @foreach ($report['totals'] as $label => $value)
            <span>{{ $label }}: {{ $report['currency'] ?? 'AED' }} {{ number_format($value, 2) }}</span>
        @endforeach
    </div>
</body>
</html>
