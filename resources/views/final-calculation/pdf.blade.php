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
    table.fc td { padding: 5px 8px; border-bottom: 1px solid #e8edf2; }
    .r { text-align: right; }
    tr.green td { background: #d1fae5; font-weight: bold; color: #065f46; }
    tr.blue td { background: #dbeafe; font-weight: bold; color: #1e40af; }
    tr.yellow td { background: #fef3c7; font-weight: bold; color: #92400e; }
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
        $fmt = fn ($v) => number_format((float) $v, 2);
        $extra = (float) $totals['cash_extra'];
        $rows = [
            ['Opening Balance', $totals['opening_balance']],
            ['Total Income', $totals['total_income']],
            ['Total Customs/Gov. Fees Paid', -$totals['customs_gov_fees']],
            ['Total Credit (Unpaid)', -$totals['credit_unpaid']],
            ['Office Expenses', -$totals['office_expenses']],
            ['TOTAL AMOUNT', $totals['total_amount'], 'yellow'],
            ['Borrowed Amount', $totals['borrowed_amount']],
            ['Daily Credit (Pending)', -$totals['daily_credit_pending']],
            ['TOTAL BALANCE AMOUNT', $totals['total_balance_amount'], 'blue'],
            ['All Bank A/C Balance', -$totals['bank_ac_balance']],
            ['CDR A/C Balance', -$totals['cdr_ac_balance']],
            ['TOTAL CASH BALANCE IN HAND', $totals['total_cash_balance'], 'green'],
        ];
    @endphp

    <div class="head">
        <div class="company">CMV Shipping</div>
        <div class="sub">Final Calculation — {{ $calc->calc_date->format('d-m-Y') }}</div>
    </div>

    <table class="fc">
        <thead>
            <tr>
                <th class="l">Details</th>
                <th>Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
                <tr class="{{ $row[2] ?? '' }}">
                    <td>{{ $row[0] }}</td>
                    <td class="r">{{ $fmt($row[1]) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="boxes"><tr>
        <td><div class="box liquid"><div class="lbl">Total Cash Balance In Hand</div><div class="val">{{ $fmt($totals['total_cash_balance']) }}</div></div></td>
        <td><div class="box"><div class="lbl">Cash Counted (AED equiv.)</div><div class="val">{{ $fmt($totals['cash_counted']) }}</div></div></td>
        <td><div class="box {{ $extra == 0 ? '' : ($extra > 0 ? 'over' : 'short') }}"><div class="lbl">Cash Extra</div>
            <div class="val">{{ $extra == 0 ? 'Balanced' : ($extra > 0 ? 'Over ' : 'Short ').number_format(abs($extra), 2) }}</div></div></td>
    </tr></table>

    @if($calc->remarks)
        <div style="margin-top:10px;"><strong>Remarks:</strong> {{ $calc->remarks }}</div>
    @endif
</body>
</html>
