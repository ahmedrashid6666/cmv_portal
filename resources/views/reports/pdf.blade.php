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
</style>
</head>
<body>
    <div class="head">
        <div class="company">CMV Shipping</div>
        <div class="title">{{ $report['title'] }}</div>
        <div style="font-size:10px;color:#64748b;margin-top:2px;">Generated {{ now()->format('d M Y H:i') }}</div>
    </div>

    <table>
        <thead>
            <tr>@foreach ($report['columns'] as $col)<th>{{ $col }}</th>@endforeach</tr>
        </thead>
        <tbody>
            @forelse ($report['rows'] as $row)
                <tr>@foreach ($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>
            @empty
                <tr><td colspan="{{ count($report['columns']) }}" style="text-align:center;color:#94a3b8;">No data.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="totals">
        @foreach ($report['totals'] as $label => $value)
            <span>{{ $label }}: AED {{ number_format($value, 2) }}</span>
        @endforeach
    </div>
</body>
</html>
