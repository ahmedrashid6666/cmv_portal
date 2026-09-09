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
    .top td.brand { width: 66%; padding-right: 12px; }
    .top td.doc { width: 34%; }
    .logo { width: 64px; }
    .company { font-size: 20px; font-weight: bold; color: #1e3a5f; }
    .muted { color: #64748b; font-size: 10px; line-height: 1.5; }
    .inv-title { font-size: 26px; font-weight: bold; color: #1b9a9b; text-align: right; }
    .badge { display: inline-block; padding: 3px 10px; border-radius: 10px; font-size: 10px; font-weight: bold; }
    .paid { background: #d1fae5; color: #065f46; }
    .partial { background: #fef3c7; color: #92400e; }
    .unpaid { background: #fee2e2; color: #991b1b; }
    .cols { width: 100%; margin: 8px 0 16px; }
    .cols td { vertical-align: top; width: 50%; font-size: 11px; }
    .label { color: #64748b; font-size: 9px; text-transform: uppercase; }
    table.items { width: 100%; border-collapse: collapse; margin-top: 8px; }
    table.items th { background: #1e3a5f; color: #fff; text-align: left; padding: 8px; font-size: 10px; }
    table.items th.r, table.items td.r { text-align: right; }
    table.items td { padding: 7px 8px; border-bottom: 1px solid #e2e8f0; }
    .totals { width: 45%; float: right; margin-top: 10px; }
    .totals td { padding: 5px 8px; font-size: 12px; }
    .totals .grand td { border-top: 2px solid #1e3a5f; font-weight: bold; font-size: 14px; color: #1e3a5f; }
    .due { color: #E63946; font-weight: bold; }
    .foot { clear: both; margin-top: 60px; border-top: 1px solid #e2e8f0; padding-top: 10px; color: #64748b; font-size: 10px; text-align: center; }
</style>
</head>
<body>
<div class="wrap">
    <table class="top">
        <tr>
            <td class="brand">
                @if ($company['logo_data_uri'])
                    <img class="logo" src="{{ $company['logo_data_uri'] }}" alt="">
                @endif
                <div class="company">{{ $invoice['company']['name'] }}</div>
                <div class="muted">
                    @if($invoice['company']['address']){{ $invoice['company']['address'] }}<br>@endif
                    @if($invoice['company']['phone']){{ $invoice['company']['phone'] }} @endif
                    @if($invoice['company']['email']) · {{ $invoice['company']['email'] }}@endif
                    @if($invoice['company']['trn'])<br>TRN: {{ $invoice['company']['trn'] }}@endif
                </div>
            </td>
            <td class="doc" style="text-align:right;">
                <div class="inv-title">INVOICE</div>
                <div class="muted"># {{ $invoice['invoice_no'] }}<br>Date: {{ \Illuminate\Support\Carbon::parse($invoice['date'])->format('d-m-Y') }}</div>
                <div style="margin-top:6px;">
                    <span class="badge {{ $invoice['status'] }}">{{ strtoupper($invoice['status']) }}</span>
                </div>
            </td>
        </tr>
    </table>

    <table class="cols">
        <tr>
            <td>
                <div class="label">Bill To</div>
                <strong>{{ $invoice['customer']['name'] }}</strong>
                @if($invoice['customer']['contact'])<br><span class="muted">{{ $invoice['customer']['contact'] }}</span>@endif
            </td>
            <td>
                <div class="label">Details</div>
                @if($invoice['boe_no'])BOE No: {{ $invoice['boe_no'] }}<br>@endif
                @if($invoice['vehicle'])Vehicle: {{ $invoice['vehicle'] }}<br>@endif
                @if($invoice['reference'])Reference: {{ $invoice['reference'] }}<br>@endif
                Payment: {{ $invoice['payment_method'] }}
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr><th>Description</th><th class="r">Amount ({{ $invoice['currency'] }})</th></tr>
        </thead>
        <tbody>
            @foreach($invoice['lines'] as $line)
                <tr><td>{{ $line['label'] }}</td><td class="r">{{ number_format($line['amount'], 2) }}</td></tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Subtotal</td><td class="r">{{ number_format($invoice['subtotal'], 2) }}</td></tr>
        <tr><td>VAT ({{ number_format($invoice['vat_rate'], 0) }}%)</td><td class="r">{{ number_format($invoice['vat_amount'], 2) }}</td></tr>
        <tr class="grand"><td>Total</td><td class="r">{{ $invoice['currency'] }} {{ number_format($invoice['total'], 2) }}</td></tr>
        <tr><td>Paid</td><td class="r">{{ number_format($invoice['paid'], 2) }}</td></tr>
        @if($invoice['outstanding'] > 0)
        <tr><td class="due">Amount Due</td><td class="r due">{{ $invoice['currency'] }} {{ number_format($invoice['outstanding'], 2) }}</td></tr>
        @endif
    </table>

    <div class="foot">{{ $invoice['footer'] }}</div>
</div>
</body>
</html>
