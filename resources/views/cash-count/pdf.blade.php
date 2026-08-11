<!DOCTYPE html>
<html>
<head><meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #10222f; }
    .head { border-bottom: 3px solid #1b9a9b; padding-bottom: 8px; margin-bottom: 14px; }
    .company { font-size: 18px; font-weight: bold; color: #1e3a5f; }
    .sub { font-size: 13px; color: #158a8b; }
    .cols { width: 100%; border-collapse: collapse; }
    .cols td { width: 50%; vertical-align: top; padding-right: 12px; }
    .cols td + td { padding-right: 0; padding-left: 12px; border-left: 1px solid #e8edf2; }

    table.den { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    table.den th { background: #f6d9c3; color: #1e3a5f; text-align: right; padding: 6px 8px; font-size: 9px; text-transform: uppercase; letter-spacing: .02em; }
    table.den th.l { text-align: left; }
    table.den td { padding: 5px 8px; border-bottom: 1px solid #e8edf2; }
    table.den tbody tr:nth-child(even) td { background: #f9fafb; }

    table.slip { border: 1px solid #e2e8f0; }
    table.slip th { border-bottom: 1px solid #eab892; }
    table.slip th.l { text-align: center; }
    table.slip td { border-bottom: 1px solid #e8edf2; }
    table.slip .tot td { background: #f6d9c3; color: #1e3a5f; text-align: right; border-bottom: none; }

    .r { text-align: right; }
    .l { text-align: left; }
    .tot { font-weight: bold; color: #1e3a5f; }
    .section-label { font-size: 9px; text-transform: uppercase; letter-spacing: .04em; color: #64748b; margin: 2px 0 4px; }

    .rec { margin-top: 4px; border-top: 2px solid #1e3a5f; padding-top: 12px; }
    .boxes { width: 100%; }
    .boxes td { width: 25%; padding: 6px; vertical-align: top; }
    .box { border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px; }
    .box .lbl { font-size: 9px; text-transform: uppercase; color: #64748b; }
    .box .val { font-size: 16px; font-weight: bold; margin-top: 3px; }
    .liquid { border-color: #6ee7b7; background: #ecfdf5; }
    .liquid .val { color: #047857; }
    .short { border-color: #fca5a5; background: #fef2f2; }
    .short .val { color: #b91c1c; }
    .over { border-color: #fcd34d; background: #fffbeb; }
    .over .val { color: #b45309; }
</style></head>
<body>
    <div class="head">
        <div class="company">CMV Shipping</div>
        <div class="sub">Daily Cash Slip — {{ $count->count_date->format('d-m-Y') }}</div>
    </div>

    <table class="cols"><tr>
    @foreach(['AED','OMR'] as $cur)
        <td>
            <table class="den">
                <thead><tr><th class="l">{{ $cur }} Denom.</th><th>Qty</th><th>Amount</th></tr></thead>
                <tbody>
                @foreach(\App\Models\CashCount::DENOMINATIONS[$cur] as $denom)
                    @php $qty = (float) ($count->lines[$cur][(string) $denom] ?? 0); @endphp
                    <tr>
                        <td class="l">{{ rtrim(rtrim(number_format($denom, 2), '0'), '.') }}</td>
                        <td class="r">{{ $qty ?: '—' }}</td>
                        <td class="r">{{ $qty ? number_format($denom * $qty, 2) : '—' }}</td>
                    </tr>
                @endforeach
                <tr class="tot"><td colspan="2">Denomination Total</td><td class="r">{{ number_format(\App\Models\CashCount::totalFor($cur, $count->lines ?? [], [], []), $cur === 'OMR' ? 3 : 2) }}</td></tr>
                </tbody>
            </table>

            @if(!empty($count->bundles[$cur]))
                <table class="den">
                    <thead><tr><th class="l">{{ $cur }} Bundles</th><th>Amount</th></tr></thead>
                    <tbody>
                    @foreach($count->bundles[$cur] as $b)
                        <tr><td class="l">{{ $b['label'] ?? '' }}</td><td class="r">{{ number_format((float)($b['amount'] ?? 0), 2) }}</td></tr>
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
                        <tr><th class="l" colspan="2">IN</th><th class="l" colspan="2">OUT</th></tr>
                        <tr><th class="l">Details</th><th>Amount</th><th class="l">Details</th><th>Amount</th></tr>
                    </thead>
                    <tbody>
                    @for($row = 0; $row < $slipRows; $row++)
                        @php $in = $slipIn($row); $out = $slipOut($row); @endphp
                        <tr>
                            <td class="l">{{ $in['label'] ?? '' }}</td>
                            <td class="r">{{ $in && (float) ($in['amount'] ?? 0) !== 0.0 ? number_format((float) $in['amount'], 2) : '' }}</td>
                            <td class="l">{{ $out['label'] ?? '' }}</td>
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

    @php $v = round($count->total_aed - $count->expected_aed, 2); @endphp
    <div class="rec">
        <table class="boxes"><tr>
            <td><div class="box"><div class="lbl">Counted (AED)</div><div class="val">{{ number_format($count->total_aed, 2) }}</div></div></td>
            <td><div class="box"><div class="lbl">Counted (OMR)</div><div class="val">{{ number_format($count->total_omr, 3) }}</div></div></td>
            <td><div class="box liquid"><div class="lbl">Expected Cash (AED)</div><div class="val">{{ number_format($count->expected_aed, 2) }}</div></div></td>
            <td><div class="box {{ $v == 0 ? '' : ($v > 0 ? 'over' : 'short') }}"><div class="lbl">Difference</div>
                <div class="val">{{ $v == 0 ? 'Balanced' : ($v > 0 ? 'Over ' : 'Short ').number_format(abs($v), 2) }}</div></div></td>
        </tr></table>
        @if($count->remarks)<p style="margin-top:10px;"><strong>Remarks:</strong> {{ $count->remarks }}</p>@endif
    </div>
</body>
</html>
