<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    * { font-family: DejaVu Sans, sans-serif; }
    body { font-size: 12px; color: #10222f; margin: 0; }
    .wrap { padding: 4px; }
    .top { width: 100%; border-bottom: 3px solid #1b9a9b; padding-bottom: 12px; margin-bottom: 16px; }
    .top td { vertical-align: top; }
    .logo { width: 64px; }
    .company { font-size: 20px; font-weight: bold; color: #1e3a5f; }
    .muted { color: #64748b; font-size: 10px; line-height: 1.5; }
    .doc-title { font-size: 24px; font-weight: bold; color: #1b9a9b; text-align: right; }
    .cols { width: 100%; margin: 8px 0 14px; }
    .cols td { vertical-align: top; width: 50%; font-size: 11px; }
    .label { color: #64748b; font-size: 9px; text-transform: uppercase; }
    .notice { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: 6px;
        padding: 8px 12px; font-size: 11px; font-weight: bold; margin-bottom: 14px; }
    table.items { width: 100%; border-collapse: collapse; margin-top: 6px; }
    table.items th { background: #1e3a5f; color: #fff; text-align: left; padding: 7px 8px; font-size: 9px; text-transform: uppercase; }
    table.items th.r, table.items td.r { text-align: right; }
    table.items td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; font-size: 10.5px; }
    table.items .sub { color: #64748b; font-size: 8.5px; }
    table.items tr.total td { border-top: 2px solid #1e3a5f; border-bottom: none; font-weight: bold; font-size: 12px; color: #b91c1c; padding-top: 8px; }
    .bank { margin-top: 22px; border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 14px; background: #f8fafc; }
    .bank .label { margin-bottom: 4px; }
    .bank table { width: 100%; }
    .bank td { padding: 2px 0; font-size: 11px; vertical-align: top; }
    .bank td.k { color: #64748b; width: 110px; }
    .foot { clear: both; margin-top: 30px; border-top: 1px solid #e2e8f0; padding-top: 10px; color: #64748b; font-size: 9.5px; text-align: center; }
</style>
</head>
<body>
<div class="wrap">
    <table class="top">
        <tr>
            <td>
                @if ($logoDataUri)
                    <img class="logo" src="{{ $logoDataUri }}" alt="">
                @endif
                <div class="company">{{ $company['name'] }}</div>
                <div class="muted">
                    @if($company['address']){{ $company['address'] }}<br>@endif
                    @if($company['phone']){{ $company['phone'] }} @endif
                    @if($company['email']) &middot; {{ $company['email'] }}@endif
                    @if($company['trn'])<br>TRN: {{ $company['trn'] }}@endif
                </div>
            </td>
            <td style="text-align:right;">
                <div class="doc-title">OUTSTANDING STATEMENT</div>
                <div class="muted">Statement Date: {{ $statementDate }}</div>
            </td>
        </tr>
    </table>

    <table class="cols">
        <tr>
            <td>
                <div class="label">Bill To</div>
                <strong>{{ $customer->name }}</strong>
                @if($customer->address)<br><span class="muted">{{ $customer->address }}</span>@endif
                @if($customer->contact)<br><span class="muted">{{ $customer->contact }}</span>@endif
                @if($customer->email)<br><span class="muted">{{ $customer->email }}</span>@endif
            </td>
            <td>
                <div class="label">Summary</div>
                Invoices Outstanding: {{ $invoices->count() }}<br>
                Total Outstanding: <strong>{{ $currency }} {{ number_format($totalOutstanding, 2) }}</strong>
            </td>
        </tr>
    </table>

    <div class="notice">
        The amount below is OUTSTANDING and pending clearance. Kindly settle at your earliest convenience.
    </div>

    <table class="items">
        <thead>
            <tr>
                <th>Date</th>
                <th>Invoice No</th>
                <th>Boe No</th>
                <th>Reference</th>
                <th>Vehicle No</th>
                <th class="r">Credit Amount</th>
                <th class="r">Paid</th>
                <th class="r">Outstanding</th>
            </tr>
        </thead>
        <tbody>
            @forelse($invoices as $inv)
                <tr>
                    <td>{{ $inv['date'] }}</td>
                    <td>{{ $inv['invoice_no'] }}</td>
                    <td>{{ $inv['boe_no'] ?: '—' }}</td>
                    <td>{{ $inv['reference'] ?: '—' }}</td>
                    <td>{{ $inv['vehicle'] ?: '—' }}</td>
                    <td class="r">{{ number_format($inv['credit_amount'], 2) }}</td>
                    <td class="r">
                        {{ number_format($inv['paid'], 2) }}
                        @if($inv['last_payment'])
                            <div class="sub">last: {{ $inv['last_payment']['date'] }} ({{ number_format($inv['last_payment']['amount'], 2) }})</div>
                        @endif
                    </td>
                    <td class="r">{{ number_format($inv['outstanding'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align:center;color:#64748b;padding:16px;">No outstanding invoices.</td></tr>
            @endforelse
            @if($invoices->isNotEmpty())
                <tr class="total">
                    <td colspan="7">Total Outstanding</td>
                    <td class="r">{{ $currency }} {{ number_format($totalOutstanding, 2) }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    @if($bank)
        <div class="bank">
            <div class="label">Please make payment to</div>
            <table>
                <tr><td class="k">Bank Name</td><td>{{ $bank->bank_name }}</td></tr>
                <tr><td class="k">Account Name</td><td>{{ $bank->account_name }}</td></tr>
                <tr><td class="k">Account Number</td><td>{{ $bank->account_number }}</td></tr>
                @if($bank->iban)<tr><td class="k">IBAN</td><td>{{ $bank->iban }}</td></tr>@endif
                @if($bank->swift_code)<tr><td class="k">SWIFT / BIC</td><td>{{ $bank->swift_code }}</td></tr>@endif
                @if($bank->branch)<tr><td class="k">Branch</td><td>{{ $bank->branch }}</td></tr>@endif
                <tr><td class="k">Currency</td><td>{{ $bank->currency }}</td></tr>
            </table>
        </div>
    @endif

    <div class="foot">
        {{ $company['footer'] }}<br>
        This is a system-generated statement and does not require a signature. Generated on {{ now()->format('d/m/Y H:i') }}.
    </div>
</div>
</body>
</html>
