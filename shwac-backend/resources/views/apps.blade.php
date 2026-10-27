<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Download the SHWAC apps</title>
<style>
  body { margin: 0; background: #F3F6F5; color: #0F2A30; font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
  .wrap { max-width: 560px; margin: 0 auto; padding: 28px 16px; }
  .card { background: #fff; border: 1px solid #DCE4E3; border-radius: 16px; padding: 16px; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; gap: 12px; }
  a.btn { background: #0B6E7A; color: #fff; text-decoration: none; font-weight: 800; padding: 12px 16px; border-radius: 12px; white-space: nowrap; }
  .muted { color: #4B5F64; font-size: 13px; }
</style>
</head>
<body>
<div class="wrap">
  <h1 style="margin:0 0 6px">SHWAC apps for Android</h1>
  <p class="muted">Tap Download, open the file, and allow "Install unknown apps" for your browser when Android asks.</p>
  @forelse($files as $f)
    <div class="card">
      <div><strong>{{ $f['name'] }}</strong><div class="muted">{{ $f['size'] }} MB · {{ $f['date'] }}</div></div>
      <a class="btn" href="{{ asset('downloads/'.$f['name']) }}" download>Download</a>
    </div>
  @empty
    <div class="card"><span class="muted">No app files uploaded yet. Put the .apk files in <code>public/downloads/</code>.</span></div>
  @endforelse
</div>
</body>
</html>
