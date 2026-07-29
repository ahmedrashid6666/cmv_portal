<!DOCTYPE html>
<html>
<head><meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #10222f; }
    .head { border-bottom: 3px solid #1b9a9b; padding-bottom: 8px; margin-bottom: 14px; }
    .company { font-size: 18px; font-weight: bold; color: #1e3a5f; }
    .cols { width: 100%; }
    .cols td { width: 50%; vertical-align: top; padding-right: 10px; }
    table.den { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    table.den th { background: #1e3a5f; color: #fff; text-align: left; padding: 5px; font-size: 10px; }
    table.den td { padding: 4px 5px; border-bottom: 1px solid #e2e8f0; }
    .r { text-align: right; }
    .tot { font-weight: bold; color: #1e3a5f; }
    .rec { margin-top: 14px; border-top: 2px solid #1e3a5f; padding-top: 8px; }
</style></head>
<body>
    <div class="head">
        <div class="company">CMV Shipping</div>
        <div style="font-size:13px;color:#158a8b;">Daily Cash Count — {{ $count->count_date->format('d-m-Y') }}</div>
    </div>

    <table class="cols"><tr>
    @foreach(['AED','OMR'] as $cur)
        <td>
            <table class="den">
                <thead><tr><th>{{ $cur }} Denomination</th><th class="r">Qty</th><th class="r">Amount</th></tr></thead>
                <tbody>
                @foreach($denominations[$cur] as $d)
                    @php $qty = (float)($count->lines[$cur][(string)$d] ?? 0); @endphp
                    <tr><td>{{ number_format($d, $d < 1 ? 2 : 0) }}</td><td class="r">{{ $qty ?: '' }}</td><td class="r">{{ $qty ? number_format($d*$qty, 2) : '' }}</td></tr>
                @endforeach
                @foreach(($count->extras[$cur] ?? []) as $x)
                    <tr><td>{{ $x['label'] ?? '' }}</td><td></td><td class="r">{{ number_format((float)($x['amount'] ?? 0), 2) }}</td></tr>
                @endforeach
                <tr class="tot"><td colspan="2">TOTAL {{ $cur }}</td><td class="r">{{ number_format($cur==='OMR' ? $count->total_omr : $count->total_aed, $cur==='OMR'?3:2) }}</td></tr>
                </tbody>
            </table>
        </td>
    @endforeach
    </tr></table>

    <div class="rec">
        <table style="width:60%"><tbody>
            <tr><td>Counted (AED)</td><td class="r tot">AED {{ number_format($count->total_aed, 2) }}</td></tr>
            <tr><td>Expected Cash (AED)</td><td class="r">AED {{ number_format($count->expected_aed, 2) }}</td></tr>
            @php $v = round($count->total_aed - $count->expected_aed, 2); @endphp
            <tr><td>Difference</td><td class="r tot">{{ $v==0 ? 'Balanced' : ($v>0?'Over':'Short').' AED '.number_format(abs($v),2) }}</td></tr>
        </tbody></table>
        @if($count->remarks)<p style="margin-top:8px;">Remarks: {{ $count->remarks }}</p>@endif
    </div>
</body>
</html>
