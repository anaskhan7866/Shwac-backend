<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>SHWAC Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<link rel="stylesheet" href="/admin-assets/admin.css?v={{ $v }}">
<script>
  window.SHWAC = {
    api: window.location.origin + '/api/v1',
    appName: @json(config('app.name', 'SHWAC')),
    paymentGateway: @json(config('shwac.payments.gateway', 'none')),
    whatsappMode: @json(config('services.whatsapp.mode', 'link')),
  };
</script>
</head>
<body>
<div id="app"><div class="boot">Loading…</div></div>
<div id="modal-root"></div>
<div id="toast-root" aria-live="polite"></div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js" defer></script>
<script src="/admin-assets/core.js?v={{ $v }}" defer></script>
<script src="/admin-assets/pages-ops.js?v={{ $v }}" defer></script>
<script src="/admin-assets/pages-money.js?v={{ $v }}" defer></script>
<script src="/admin-assets/pages-stock.js?v={{ $v }}" defer></script>
<script src="/admin-assets/pages-admin.js?v={{ $v }}" defer></script>
<script src="/admin-assets/boot.js?v={{ $v }}" defer></script>
</body>
</html>
