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
    table.slip th { text-align: center; border: 1px solid #10222f; }
    table.slip td { border: 1px solid #cbd5e1; font-size: 10px; }
    table.slip .tot td { background: #f1f5f9; text-align: right; }
    .r { text-align: right; }
    .tot { font-weight: bold; color: #1e3a5f; }
    .rec { margin-top: 14px; border-top: 2px solid #1e3a5f; padding-top: 8px; }
</style></head>
<body>
    <div class="head">
        <div class="company">CMV Shipping</div>
        <div style="font-size:13px;color:#158a8b;">Daily Cash Slip — {{ $count->count_date->format('d-m-Y') }}</div>
    </div>

    <table class="cols"><tr>
    @foreach(['AED','OMR'] as $cur)
        <td>
            <table class="den">
                <thead><tr><th>{{ $cur }} Denom.</th><th class="r">Qty</th><th class="r">Amount</th></tr></thead>
                <tbody>
                @foreach(\App\Models\CashCount::DENOMINATIONS[$cur] as $denom)
                    @php $qty = (float) ($count->lines[$cur][(string) $denom] ?? 0); @endphp
                    <tr>
                        <td>{{ rtrim(rtrim(number_format($denom, 2), '0'), '.') }}</td>
                        <td class="r">{{ $qty ?: '—' }}</td>
                        <td class="r">{{ $qty ? number_format($denom * $qty, 2) : '—' }}</td>
                    </tr>
                @endforeach
                <tr class="tot"><td colspan="2">Denomination Total</td><td class="r">{{ number_format(\App\Models\CashCount::totalFor($cur, $count->lines ?? [], [], []), $cur === 'OMR' ? 3 : 2) }}</td></tr>
                </tbody>
            </table>
            @if(!empty($count->bundles[$cur]))
                <table class="den">
                    <thead><tr><th>{{ $cur }} Bundles</th><th class="r">Amount</th></tr></thead>
                    <tbody>
                    @foreach($count->bundles[$cur] as $b)
                        <tr><td>{{ $b['label'] ?? '' }}</td><td class="r">{{ number_format((float)($b['amount'] ?? 0), 2) }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
            @php
                $slipExtras = $count->extras[$cur] ?? [];
                $hasSlips = collect($slipExtras)->contains(fn ($x) => trim($x['label'] ?? '') !== '' || (float) ($x['amount'] ?? 0) !== 0.0);
                $slipRows = max((int) ceil(count($slipExtras) / 2), 1);
                $slipIn = fn ($row) => $slipExtras[$row * 2] ?? null;
                $slipOut = fn ($row) => $slipExtras[$row * 2 + 1] ?? null;
            @endphp
            @if($hasSlips)
                <table class="den slip">
                    <thead>
                        <tr><th colspan="2">IN</th><th colspan="2">OUT</th></tr>
                        <tr><th>Details</th><th class="r">Amount</th><th>Details</th><th class="r">Amount</th></tr>
                    </thead>
                    <tbody>
                    @for($row = 0; $row < $slipRows; $row++)
                        @php $in = $slipIn($row); $out = $slipOut($row); @endphp
                        <tr>
                            <td>{{ $in['label'] ?? '' }}</td>
                            <td class="r">{{ $in && (float) ($in['amount'] ?? 0) !== 0.0 ? number_format((float) $in['amount'], 2) : '' }}</td>
                            <td>{{ $out['label'] ?? '' }}</td>
                            <td class="r">{{ $out && (float) ($out['amount'] ?? 0) !== 0.0 ? number_format((float) $out['amount'], 2) : '' }}</td>
                        </tr>
                    @endfor
                    <tr class="tot"><td colspan="4">{{ $cur }} Balance Amount: {{ number_format(\App\Models\CashCount::extrasBalanceFor($cur, $count->extras ?? []), 2) }}</td></tr>
                    </tbody>
                </table>
            @endif
        </td>
    @endforeach
    </tr></table>

    <div class="rec">
        <table style="width:60%"><tbody>
            <tr><td>Counted (AED)</td><td class="r tot">AED {{ number_format($count->total_aed, 2) }}</td></tr>
            <tr><td>Counted (OMR)</td><td class="r tot">OMR {{ number_format($count->total_omr, 3) }}</td></tr>
            <tr><td>Expected Cash (AED)</td><td class="r">AED {{ number_format($count->expected_aed, 2) }}</td></tr>
            @php $v = round($count->total_aed - $count->expected_aed, 2); @endphp
            <tr><td>Difference</td><td class="r tot">{{ $v==0 ? 'Balanced' : ($v>0?'Over':'Short').' AED '.number_format(abs($v),2) }}</td></tr>
        </tbody></table>
        @if($count->remarks)<p style="margin-top:8px;">Remarks: {{ $count->remarks }}</p>@endif
    </div>
</body>
</html>
