@php
    $company = \App\Models\Setting::get('company', []);
    $branding = \App\Models\Setting::get('branding', []);
    $b = $invoice->branch;
    $addr = $invoice->billing_address ?? [];
    $intra = $invoice->supply_type === 'intra';
    $inr = fn ($v) => number_format((float) $v, 2);
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Tax Invoice {{ $invoice->number ?? 'DRAFT' }}</title>
<style>
    * { box-sizing: border-box; }
    body { font: 12px/1.45 "Helvetica Neue", Arial, sans-serif; color: #1d2327; margin: 24px; }
    .head { display: flex; justify-content: space-between; border-bottom: 3px solid {{ $branding['primary_color'] ?? '#0b6e99' }}; padding-bottom: 12px; }
    h1 { font-size: 20px; margin: 0; color: {{ $branding['primary_color'] ?? '#0b6e99' }}; }
    .muted { color: #5f6b73; }
    .title { text-align: right; }
    .title h2 { margin: 0; font-size: 16px; letter-spacing: .08em; }
    .grid { display: flex; gap: 24px; margin: 16px 0; }
    .grid > div { flex: 1; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 6px 8px; border-bottom: 1px solid #e3e7ea; text-align: left; vertical-align: top; }
    th { background: #f3f6f8; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
    td.n, th.n { text-align: right; white-space: nowrap; }
    .totals { width: 320px; margin-left: auto; margin-top: 12px; }
    .totals td { border: 0; padding: 3px 8px; }
    .totals tr.grand td { font-size: 15px; font-weight: 700; border-top: 2px solid #1d2327; padding-top: 8px; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; background: #fde8e8; color: #b42318; font-weight: 600; }
    .foot { margin-top: 32px; display: flex; justify-content: space-between; font-size: 11px; }
    @media print { body { margin: 0; } }
</style>
</head>
<body>
<div class="head">
    <div>
        <h1>{{ $company['legal_name'] ?? $company['name'] ?? config('app.name') }}</h1>
        <div class="muted">{{ $b->address }}{{ $b->city ? ', '.$b->city : '' }}{{ $b->pincode ? ' - '.$b->pincode : '' }}</div>
        <div class="muted">{{ $b->state }} ({{ $b->state_code }}) · {{ $b->phone }}</div>
        @if($b->gstin)<div><strong>GSTIN:</strong> {{ $b->gstin }}</div>@endif
    </div>
    <div class="title">
        <h2>TAX INVOICE</h2>
        @if($invoice->status === 'draft')<div class="badge">DRAFT — NOT VALID</div>@endif
        @if($invoice->status === 'cancelled')<div class="badge">CANCELLED</div>@endif
        <div><strong>No:</strong> {{ $invoice->number ?? '—' }}</div>
        <div><strong>Date:</strong> {{ $invoice->invoice_date?->format('d-m-Y') ?? '—' }}</div>
        @if($invoice->due_date)<div><strong>Due:</strong> {{ $invoice->due_date->format('d-m-Y') }}</div>@endif
    </div>
</div>

<div class="grid">
    <div>
        <div class="muted">Bill to</div>
        <strong>{{ $addr['name'] ?? $invoice->customer->name }}</strong><br>
        {{ $addr['line1'] ?? '' }}{{ !empty($addr['line2']) ? ', '.$addr['line2'] : '' }}<br>
        {{ $addr['city'] ?? '' }} {{ $addr['pincode'] ?? '' }}<br>
        Phone: {{ $addr['phone'] ?? $invoice->customer->phone }}
        @if($invoice->customer_gstin)<br>GSTIN: {{ $invoice->customer_gstin }}@endif
    </div>
    <div>
        <div class="muted">Place of supply</div>
        <strong>{{ $addr['state'] ?? '' }} ({{ $invoice->place_of_supply }})</strong><br>
        <span class="muted">Supply type:</span> {{ $intra ? 'Intra-state (CGST + SGST)' : 'Inter-state (IGST)' }}<br>
        <span class="muted">Reverse charge:</span> No
    </div>
</div>

<table>
    <thead>
    <tr>
        <th>#</th><th>Description</th><th>HSN/SAC</th><th class="n">Qty</th><th class="n">Rate</th>
        <th class="n">Disc.</th><th class="n">Taxable</th>
        @if($intra)<th class="n">CGST</th><th class="n">SGST</th>@else<th class="n">IGST</th>@endif
        <th class="n">Total</th>
    </tr>
    </thead>
    <tbody>
    @foreach($invoice->items as $i => $it)
        <tr>
            <td>{{ $i + 1 }}</td>
            <td>{{ $it->description }}</td>
            <td>{{ $it->hsn_sac }}</td>
            <td class="n">{{ rtrim(rtrim($it->quantity, '0'), '.') }} {{ $it->unit }}</td>
            <td class="n">{{ $inr($it->unit_price) }}</td>
            <td class="n">{{ $inr($it->discount) }}</td>
            <td class="n">{{ $inr($it->taxable_value) }}</td>
            @if($intra)
                <td class="n">{{ $inr($it->cgst_amount) }}<br><span class="muted">{{ (float) $it->cgst_rate }}%</span></td>
                <td class="n">{{ $inr($it->sgst_amount) }}<br><span class="muted">{{ (float) $it->sgst_rate }}%</span></td>
            @else
                <td class="n">{{ $inr($it->igst_amount) }}<br><span class="muted">{{ (float) $it->igst_rate }}%</span></td>
            @endif
            <td class="n">{{ $inr($it->total) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="totals">
    <tr><td>Taxable value</td><td class="n">₹{{ $inr($invoice->taxable_value) }}</td></tr>
    @if($intra)
        <tr><td>CGST</td><td class="n">₹{{ $inr($invoice->cgst_total) }}</td></tr>
        <tr><td>SGST</td><td class="n">₹{{ $inr($invoice->sgst_total) }}</td></tr>
    @else
        <tr><td>IGST</td><td class="n">₹{{ $inr($invoice->igst_total) }}</td></tr>
    @endif
    <tr><td>Round off</td><td class="n">{{ $inr($invoice->round_off) }}</td></tr>
    <tr class="grand"><td>Total</td><td class="n">₹{{ $inr($invoice->total) }}</td></tr>
    <tr><td>Paid</td><td class="n">₹{{ $inr($invoice->amount_paid) }}</td></tr>
    <tr><td><strong>Balance due</strong></td><td class="n"><strong>₹{{ $inr($invoice->balance_due) }}</strong></td></tr>
</table>

<div class="foot">
    <div class="muted" style="max-width: 60%">{!! nl2br(e($branding['invoice_terms'] ?? 'Goods once sold will not be taken back. Subject to local jurisdiction.')) !!}</div>
    <div style="text-align:right">For {{ $company['legal_name'] ?? $company['name'] ?? config('app.name') }}<br><br><br>Authorised Signatory</div>
</div>
</body>
</html>
