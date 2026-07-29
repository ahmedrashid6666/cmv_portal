<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { margin: 18px; color: #0f172a; }
        .head { border-bottom: 2px solid #158a8b; padding-bottom: 8px; margin-bottom: 12px; }
        .brand { font-size: 18px; font-weight: bold; color: #0f2a43; }
        .title { font-size: 13px; color: #158a8b; margin-top: 2px; }
        .muted { font-size: 10px; color: #64748b; margin-top: 2px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th { background: #0f2a43; color: #fff; font-size: 9px; text-transform: uppercase; text-align: left; padding: 5px 6px; }
        td { font-size: 9.5px; padding: 4px 6px; border-bottom: 1px solid #e2e8f0; }
        tr.totals td { font-weight: bold; background: #f1f5f9; border-top: 2px solid #cbd5e1; }
        .num { text-align: right; }
    </style>
</head>
<body>
    <div class="head">
        <div class="brand">CMV Shipping</div>
        <div class="title">{{ $title }}</div>
        <div class="muted">Generated {{ now()->format('d-m-Y h:i A') }} · {{ count($rows) - ($hasTotals ? 1 : 0) }} record(s){{ $range ? ' · '.$range : '' }}</div>
    </div>

    <table>
        <thead>
            <tr>
                @foreach ($columns as $i => $col)
                    <th class="{{ ($align[$i] ?? false) ? 'num' : '' }}">{{ $col }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $ri => $row)
                <tr class="{{ ($hasTotals && $ri === count($rows) - 1) ? 'totals' : '' }}">
                    @foreach ($row as $i => $cell)
                        <td class="{{ ($align[$i] ?? false) ? 'num' : '' }}">{{ $cell }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
