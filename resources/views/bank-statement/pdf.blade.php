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
    .num { text-align: right; }
    .opening td { background: #f5f7fb; color: #64748b; font-weight: bold; }
    .totals { margin-top: 12px; }
    .totals span { display: inline-block; margin-right: 18px; font-weight: bold; color: #1e3a5f; }
</style>
</head>
<body>
    <div class="head">
        <div class="company">CMV Shipping</div>
        <div class="title">Bank Statement — {{ $statement['bank']['name'] }}</div>
        <div style="font-size:10px;color:#64748b;margin-top:2px;">Generated {{ now()->format('d-m-Y h:i A') }}</div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Description</th>
                <th>Invoice</th>
                <th class="num">In</th>
                <th class="num">Out</th>
                <th class="num">Balance</th>
            </tr>
        </thead>
        <tbody>
            <tr class="opening">
                <td colspan="5">Opening balance</td>
                <td class="num">{{ number_format($statement['opening'], 2) }}</td>
            </tr>
            @forelse ($statement['rows'] as $row)
                <tr>
                    <td>{{ \Illuminate\Support\Carbon::parse($row['date'])->format('d-m-Y') }}</td>
                    <td>{{ $row['description'] }}</td>
                    <td>{{ $row['ref'] ?? '—' }}</td>
                    <td class="num">{{ $row['debit'] > 0 ? number_format($row['debit'], 2) : '—' }}</td>
                    <td class="num">{{ $row['credit'] > 0 ? number_format($row['credit'], 2) : '—' }}</td>
                    <td class="num">{{ number_format($row['balance'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" style="text-align:center;color:#94a3b8;">No activity in this period.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="totals">
        <span>Total In: AED {{ number_format($statement['total_in'], 2) }}</span>
        <span>Total Out: AED {{ number_format($statement['total_out'], 2) }}</span>
        <span>Closing Balance: AED {{ number_format($statement['closing'], 2) }}</span>
    </div>
</body>
</html>
