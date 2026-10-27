@php
    $company = \App\Models\Setting::get('company', []);
    $b = $invoice->branch;
    $intra = $invoice->supply_type === 'intra';
    $inr = fn ($v) => '₹'.number_format((float) $v, 2);
    $paid = (float) $invoice->balance_due <= 0 && $invoice->status !== 'cancelled';
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Invoice {{ $invoice->number }} · {{ $company['name'] ?? config('app.name') }}</title>
<style>
  :root { --ink:#0F2A30; --muted:#4B5F64; --line:#DCE4E3; --ground:#F3F6F5; --teal:#0B6E7A; --ok:#17663A; --okbg:#E6F4EA; --warn:#9A4B00; --warnbg:#FFF1E0; --bad:#B42318; }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--ground); color: var(--ink); font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
  .wrap { max-width: 560px; margin: 0 auto; padding: 20px 16px 40px; }
  .card { background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 18px; margin-bottom: 14px; }
  h1 { font-size: 20px; margin: 0; }
  .muted { color: var(--muted); font-size: 13px; }
  .row { display: flex; justify-content: space-between; gap: 12px; padding: 4px 0; }
  .item { border-bottom: 1px solid #EDF2F1; padding: 8px 0; }
  .total { font-size: 22px; font-weight: 800; }
  .pill { display: inline-block; border-radius: 999px; padding: 3px 12px; font-size: 13px; font-weight: 700; }
  .btn { display: block; width: 100%; text-align: center; border: 0; border-radius: 12px; padding: 14px; font-size: 16px; font-weight: 800; text-decoration: none; cursor: pointer; }
  .btn-primary { background: var(--teal); color: #fff; }
  .btn-outline { background: #fff; color: var(--ink); border: 1px solid #C9D5D4; }
  input { width: 100%; font: inherit; padding: 12px; border: 1px solid #C9D5D4; border-radius: 10px; margin: 8px 0; }
  #qr { display: flex; justify-content: center; padding: 12px 0; }
  .note { background: var(--okbg); color: var(--ok); border-radius: 12px; padding: 12px; font-weight: 600; margin-bottom: 14px; }
  .err { color: var(--bad); font-size: 13px; }
  @media print { .no-print { display: none; } body { background: #fff; } }
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="row" style="align-items:flex-start">
      <div>
        <div class="muted">{{ $company['legal_name'] ?? $company['name'] ?? config('app.name') }}</div>
        <h1>Tax invoice {{ $invoice->number }}</h1>
        <div class="muted">{{ $invoice->invoice_date?->format('d M Y') }} · {{ $b->name }}@if($b->gstin) · GSTIN {{ $b->gstin }}@endif</div>
      </div>
      @if($invoice->status === 'cancelled')
        <span class="pill" style="background:#FDECEA;color:var(--bad)">Cancelled</span>
      @elseif($paid)
        <span class="pill" style="background:var(--okbg);color:var(--ok)">Paid</span>
      @else
        <span class="pill" style="background:var(--warnbg);color:var(--warn)">Due {{ $invoice->due_date?->format('d M') }}</span>
      @endif
    </div>
    <div class="muted" style="margin-top:8px">Billed to {{ $invoice->billing_address['name'] ?? $invoice->customer->name }}</div>
  </div>

  @if(session('status'))<div class="note">{{ session('status') }}</div>@endif

  <div class="card">
    @foreach($invoice->items as $it)
      <div class="item">
        <div class="row"><strong>{{ $it->description }}</strong><strong>{{ $inr($it->taxable_value) }}</strong></div>
        <div class="muted">{{ $it->item_type === 'service' ? 'SAC' : 'HSN' }} {{ $it->hsn_sac }} · {{ rtrim(rtrim($it->quantity, '0'), '.') }} × {{ $inr($it->unit_price) }}</div>
      </div>
    @endforeach
    <div class="row" style="margin-top:8px"><span class="muted">Taxable value</span><span>{{ $inr($invoice->taxable_value) }}</span></div>
    @if($intra)
      <div class="row"><span class="muted">CGST</span><span>{{ $inr($invoice->cgst_total) }}</span></div>
      <div class="row"><span class="muted">SGST</span><span>{{ $inr($invoice->sgst_total) }}</span></div>
    @else
      <div class="row"><span class="muted">IGST</span><span>{{ $inr($invoice->igst_total) }}</span></div>
    @endif
    <div class="row"><span class="muted">Round off</span><span>{{ $inr($invoice->round_off) }}</span></div>
    <div class="row" style="border-top:1px solid var(--line);margin-top:6px;padding-top:10px"><strong>Total</strong><span class="total">{{ $inr($invoice->total) }}</span></div>
    @if((float) $invoice->amount_paid > 0)<div class="row"><span class="muted">Paid</span><span>{{ $inr($invoice->amount_paid) }}</span></div>@endif
    @if(! $paid && $invoice->status !== 'cancelled')<div class="row"><strong>Balance due</strong><strong>{{ $inr($invoice->balance_due) }}</strong></div>@endif
  </div>

  @if($upi)
    <div class="card no-print">
      <h1 style="font-size:18px">Pay {{ $inr($invoice->balance_due) }} by UPI</h1>
      <div class="muted">No extra charges. Use Google Pay, PhonePe, Paytm, BHIM or your bank app.</div>
      <a class="btn btn-primary" style="margin-top:14px" href="{{ $upi }}">Pay with a UPI app</a>
      <div class="muted" style="text-align:center;margin-top:12px">On a computer? Scan this with your phone:</div>
      <div id="qr" aria-label="UPI QR code"></div>

      @if($pending->isNotEmpty())
        <div class="note" style="background:var(--warnbg);color:var(--warn)">We received your payment reference {{ $pending->first()->reference }} and are confirming it.</div>
      @else
        <form method="post" action="{{ url('/i/'.$invoice->public_token.'/paid') }}">
          @csrf
          <label for="reference" style="font-weight:700;display:block;margin-top:10px">Already paid? Enter the UPI reference (UTR)</label>
          <input id="reference" name="reference" inputmode="numeric" autocomplete="off" placeholder="e.g. 429815736201" value="{{ old('reference') }}" required>
          @error('reference')<div class="err">{{ $message }}</div>@enderror
          <button class="btn btn-outline" type="submit">I have paid</button>
        </form>
      @endif

      @if($gateway)
        <a class="btn btn-outline" style="margin-top:10px" href="{{ url('/i/'.$invoice->public_token.'/pay-online') }}">Pay by card / netbanking</a>
      @endif
    </div>
  @endif

  <div class="no-print" style="display:flex;gap:10px">
    <button class="btn btn-outline" onclick="window.print()">Print / save as PDF</button>
  </div>
  <p class="muted" style="text-align:center;margin-top:16px">{{ $company['phone'] ?? $b->phone }}</p>
</div>
@if($upi)
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
  if (window.QRCode) new QRCode(document.getElementById('qr'), { text: @json($upi), width: 200, height: 200, correctLevel: QRCode.CorrectLevel.M });
</script>
@endif
</body>
</html>
