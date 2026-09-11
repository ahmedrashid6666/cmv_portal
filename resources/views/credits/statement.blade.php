<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    * { font-family: DejaVu Sans, sans-serif; }
    body { font-size: 11px; color: #10222f; margin: 0; }
    .wrap { padding: 4px; }
    @if($letterheadDataUri)
        @page { margin: 34mm 12mm 26mm 12mm; }
        .letterhead { position: fixed; top: -34mm; left: -12mm; width: 210mm; height: 297mm; z-index: -1; }
        .letterhead img { width: 210mm; height: 297mm; }
    @endif
    .top { width: 100%; border-bottom: 2px solid #1b9a9b; padding-bottom: 6px; margin-bottom: 8px; }
    .top td { vertical-align: top; }
    .top td.brand { width: 66%; padding-right: 12px; }
    .top td.doc { width: 34%; }
    .banner { width: 100%; margin-bottom: 4px; }
    .banner img { width: 100%; max-height: 60px; }
    .logo { width: 48px; }
    .company { font-size: 16px; font-weight: bold; color: #1e3a5f; }
    .muted { color: #64748b; font-size: 9px; line-height: 1.3; }
    .doc-title { font-size: 18px; font-weight: bold; color: #1b9a9b; text-align: right; }
    .cols { width: 100%; margin: 4px 0 6px; }
    .cols td { vertical-align: top; width: 50%; font-size: 10px; }
    .label { color: #64748b; font-size: 8px; text-transform: uppercase; }
    .notice { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: 4px;
        padding: 4px 10px; font-size: 10px; font-weight: bold; margin-bottom: 6px; }
    table.items { width: 100%; border-collapse: collapse; margin-top: 2px; }
    table.items th { background: #1e3a5f; color: #fff; text-align: left; padding: 4px 6px; font-size: 8px; text-transform: uppercase; white-space: nowrap; }
    table.items th.r, table.items td.r { text-align: right; }
    table.items td { padding: 3px 6px; border-bottom: 1px solid #e2e8f0; font-size: 9.5px; }
    table.items .sub { color: #64748b; font-size: 8px; }
    table.items tr.total td { border-top: 2px solid #1e3a5f; border-bottom: none; font-weight: bold; font-size: 11px; color: #b91c1c; padding-top: 5px; }
    .bank { margin-top: 10px; border: 1px solid #cbd5e1; border-radius: 4px; padding: 6px 10px; background: #f8fafc; }
    .bank .label { margin-bottom: 2px; }
    .bank table { width: 100%; }
    .bank td { padding: 1px 0; font-size: 10px; vertical-align: top; }
    .bank td.k { color: #64748b; width: 100px; }
    .foot { clear: both; margin-top: 10px; border-top: 1px solid #e2e8f0; padding-top: 5px; color: #64748b; font-size: 8.5px; text-align: center; }
    @if($letterheadDataUri)
        .foot { position: fixed; bottom: 0; left: 0; width: 186mm; margin-top: 0; }
    @endif
</style>
</head>
<body>
@if($letterheadDataUri)
    <div class="letterhead"><img src="{{ $letterheadDataUri }}" alt=""></div>
@endif
<div class="wrap">
    @if ($letterheadDataUri)
        <div style="text-align:center; border-top: none; border-bottom: none;">
            <div class="doc-title" style="text-align:center;">OUTSTANDING STATEMENT</div>
            <div class="muted" style="text-align:center;">
                @if($invoiceNo)Invoice No: {{ $invoiceNo }}<br>@endif
                Statement Date: {{ $statementDate }}
                @if($paymentMode)<br>Mode of Payment: {{ $paymentMode }}@endif
                @if($period)
                    <br>Period:
                    @if($period['from'] && $period['to'])
                        {{ \Illuminate\Support\Carbon::parse($period['from'])->format('d-m-Y') }} to {{ \Illuminate\Support\Carbon::parse($period['to'])->format('d-m-Y') }}
                    @elseif($period['from'])
                        from {{ \Illuminate\Support\Carbon::parse($period['from'])->format('d-m-Y') }}
                    @else
                        until {{ \Illuminate\Support\Carbon::parse($period['to'])->format('d-m-Y') }}
                    @endif
                @endif
            </div>
        </div>
    @elseif ($headerBannerDataUri)
        <div class="banner">
            <img src="{{ $headerBannerDataUri }}" alt="{{ $company['name'] }}">
        </div>
        <table class="top" style="border-top: none;">
            <tr>
                <td class="brand">
                    <div class="muted">
                        @if($company['address']){{ $company['address'] }}<br>@endif
                        @if($company['phone']){{ $company['phone'] }} @endif
                        @if($company['email']) &middot; {{ $company['email'] }}@endif
                        @if($company['trn'])<br>TRN: {{ $company['trn'] }}@endif
                    </div>
                </td>
                <td class="doc" style="text-align:right;">
                    <div class="doc-title">OUTSTANDING STATEMENT</div>
                    <div class="muted">
                        @if($invoiceNo)Invoice No: {{ $invoiceNo }}<br>@endif
                        Statement Date: {{ $statementDate }}
                        @if($paymentMode)<br>Mode of Payment: {{ $paymentMode }}@endif
                        @if($period)
                            <br>Period:
                            @if($period['from'] && $period['to'])
                                {{ \Illuminate\Support\Carbon::parse($period['from'])->format('d-m-Y') }} to {{ \Illuminate\Support\Carbon::parse($period['to'])->format('d-m-Y') }}
                            @elseif($period['from'])
                                from {{ \Illuminate\Support\Carbon::parse($period['from'])->format('d-m-Y') }}
                            @else
                                until {{ \Illuminate\Support\Carbon::parse($period['to'])->format('d-m-Y') }}
                            @endif
                        @endif
                    </div>
                </td>
            </tr>
        </table>
    @else
        <table class="top">
            <tr>
                <td class="brand">
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
                <td class="doc" style="text-align:right;">
                    <div class="doc-title">OUTSTANDING STATEMENT</div>
                    <div class="muted">
                        @if($invoiceNo)Invoice No: {{ $invoiceNo }}<br>@endif
                        Statement Date: {{ $statementDate }}
                        @if($paymentMode)<br>Mode of Payment: {{ $paymentMode }}@endif
                        @if($period)
                            <br>Period:
                            @if($period['from'] && $period['to'])
                                {{ \Illuminate\Support\Carbon::parse($period['from'])->format('d-m-Y') }} to {{ \Illuminate\Support\Carbon::parse($period['to'])->format('d-m-Y') }}
                            @elseif($period['from'])
                                from {{ \Illuminate\Support\Carbon::parse($period['from'])->format('d-m-Y') }}
                            @else
                                until {{ \Illuminate\Support\Carbon::parse($period['to'])->format('d-m-Y') }}
                            @endif
                        @endif
                    </div>
                </td>
            </tr>
        </table>
    @endif

    <table class="cols">
        <tr>
            <td>
                <div class="label">Bill To</div>
                <strong>{{ $billTo['name'] }}</strong>
                @foreach($billTo['lines'] as $line)
                    <br><span class="muted">{{ $line }}</span>
                @endforeach
            </td>
            <td style="text-align:right;">
                <div class="label">Summary</div>
                Invoices Outstanding: {{ $invoices->count() }}<br>
                Total Outstanding: <strong>{{ $currency }} {{ number_format($totalOutstanding, 2) }}</strong>
            </td>
        </tr>
    </table>

    <div class="notice">
        The amount below is OUTSTANDING. Kindly settle at your earliest convenience.
    </div>

    <table class="items">
        <thead>
            <tr>
                <th>S.No</th>
                <th>Date</th>
                <th>Invoice No</th>
                <th>Boe No</th>
                @if($showCompany)<th>Company Name</th>@endif
                <th>Vehicle No</th>
                <th class="r">Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse($invoices as $inv)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $inv['date'] }}</td>
                    <td>{{ $inv['invoice_no'] }}</td>
                    <td>{{ $inv['boe_no'] ?: '—' }}</td>
                    @if($showCompany)<td>{{ $inv['company'] ?: '—' }}</td>@endif
                    <td>{{ $inv['vehicle'] ?: '—' }}</td>
                    <td class="r">{{ number_format($inv['outstanding'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="{{ $showCompany ? 7 : 6 }}" style="text-align:center;color:#64748b;padding:16px;">No outstanding invoices.</td></tr>
            @endforelse
            @if($invoices->isNotEmpty())
                <tr class="total">
                    <td colspan="{{ $showCompany ? 6 : 5 }}">Total Outstanding</td>
                    <td class="r">{{ $currency }} {{ number_format($totalOutstanding, 2) }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    @if($invoices->isNotEmpty())
        <p style="margin-top:8px; font-size:10.5px;"><span class="label">Amount In Words:</span> {{ $amountInWords }}</p>
    @endif

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
        This is a system-generated statement and does not require a signature. Generated on {{ now()->format('d/m/Y H:i') }}.
        @unless($letterheadDataUri)
            <br><strong>{{ $company['name'] }}</strong>
            @if($company['address']) &middot; {{ $company['address'] }}@endif
            @if($company['phone']) &middot; Tel: {{ $company['phone'] }}@endif
            @if($company['email']) &middot; Email: {{ $company['email'] }}@endif
        @endunless
    </div>
</div>
</body>
</html>
