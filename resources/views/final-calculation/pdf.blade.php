<!DOCTYPE html>
<html>
<head><meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #10222f; }
    .head { border-bottom: 3px solid #1b9a9b; padding-bottom: 8px; margin-bottom: 14px; }
    .company { font-size: 18px; font-weight: bold; color: #1e3a5f; }
    .sub { font-size: 13px; color: #158a8b; }
    table.fc { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    table.fc th { background: #f6d9c3; color: #1e3a5f; text-align: right; padding: 6px; font-size: 10px; text-transform: uppercase; }
    table.fc th.l { text-align: left; }
    table.fc td { padding: 4px 6px; border-bottom: 1px solid #e8edf2; }
    .r { text-align: right; }
    .grp { font-size: 9px; text-transform: uppercase; color: #94a3b8; font-weight: bold; padding-top: 8px; }
    .banks td { background: #f0fbf4; }
    .other td { background: #fffaf0; }
    .tag { font-size: 8px; background: #eef2f6; color: #64748b; padding: 1px 4px; border-radius: 3px; }
    tr.tot td { border-top: 2px solid #cbd5e1; font-weight: bold; color: #1e3a5f; background: #f8fafc; }
    .boxes { width: 100%; margin-top: 6px; }
    .boxes td { width: 33.3%; padding: 8px; vertical-align: top; }
    .box { border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px; }
    .box .lbl { font-size: 9px; text-transform: uppercase; color: #64748b; }
    .box .val { font-size: 18px; font-weight: bold; margin-top: 3px; }
    .liquid { border-color: #6ee7b7; background: #ecfdf5; }
    .liquid .val { color: #047857; }
    .short { border-color: #fca5a5; background: #fef2f2; }
    .short .val { color: #b91c1c; }
    .over { border-color: #fcd34d; background: #fffbeb; }
    .over .val { color: #b45309; }
</style></head>
<body>
    @php
        $rows = $calc->data['rows'] ?? [];
        $rate = (float) ($calc->data['omr_rate'] ?? 9.5238);
        $groups = ['top' => 'Cash & Credit', 'banks' => 'Bank Accounts', 'other' => 'Expenses & Other'];
        $fmt = fn ($v) => is_numeric($v) && (float) $v != 0 ? number_format((float) $v, 2) : '';
        $extra = (float) $totals['cash_extra'];
    @endphp

    <div class="head">
        <div class="company">CMV Shipping</div>
        <div class="sub">Final Calculation — {{ $calc->calc_date->format('d-m-Y') }}</div>
    </div>

    <table class="fc">
        <thead>
            <tr>
                <th class="l">Final Calculation</th>
                <th>Amount</th>
                <th>A/C Balance</th>
                <th>Debt / Exp</th>
                <th>Cash (AED)</th>
                <th>Cash (OMR)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($groups as $gkey => $glabel)
                <tr class="{{ $gkey }}"><td colspan="6" class="grp">{{ $glabel }}</td></tr>
                @foreach($rows as $r)
                    @continue(($r['group'] ?? 'top') !== $gkey)
                    <tr class="{{ $gkey }}">
                        <td>{{ $r['label'] ?? '' }}
                            @if(($r['currency'] ?? 'AED') === 'OMR')<span class="tag">OMR</span>@endif
                        </td>
                        <td class="r">{{ $fmt($r['amount'] ?? null) }}</td>
                        <td class="r">{{ $fmt($r['ac_balance'] ?? null) }}</td>
                        <td class="r">{{ $fmt($r['debt_exp'] ?? null) }}</td>
                        <td class="r">{{ $fmt($r['cash_aed'] ?? null) }}</td>
                        <td class="r">{{ $fmt($r['cash_omr'] ?? null) }}</td>
                    </tr>
                @endforeach
            @endforeach
            <tr class="tot">
                <td>TOTAL</td>
                <td class="r">{{ number_format($totals['total_amount'], 2) }}</td>
                <td class="r">{{ number_format($totals['total_ac_balance'], 2) }}</td>
                <td class="r">{{ number_format($totals['total_debt_exp'], 2) }}</td>
                <td class="r" colspan="2">{{ number_format($totals['cash_counted'], 2) }} (AED)</td>
            </tr>
        </tbody>
    </table>

    <div style="font-size:9px;color:#94a3b8;margin-bottom:4px;">
        Liquid Cash = Amount − (A/C Balance + Debt/Exp). Cash column OMR converted at {{ number_format($rate, 4) }}.
    </div>

    <table class="boxes"><tr>
        <td><div class="box liquid"><div class="lbl">Total Liquid Cash in CMV</div><div class="val">{{ number_format($totals['liquid_cash'], 2) }}</div></div></td>
        <td><div class="box"><div class="lbl">Cash Counted (AED)</div><div class="val">{{ number_format($totals['cash_counted'], 2) }}</div></div></td>
        <td><div class="box {{ $extra == 0 ? '' : ($extra > 0 ? 'over' : 'short') }}"><div class="lbl">Cash Extra</div>
            <div class="val">{{ $extra == 0 ? 'Balanced' : ($extra > 0 ? 'Over ' : 'Short ').number_format(abs($extra), 2) }}</div></div></td>
    </tr></table>

    @if($calc->remarks)
        <div style="margin-top:10px;"><strong>Remarks:</strong> {{ $calc->remarks }}</div>
    @endif
</body>
</html>
