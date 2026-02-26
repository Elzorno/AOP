<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'AOP') }}</title>

    <style>
        :root { --bg:#f7f7f8; --card:#ffffff; --text:#111827; --muted:#6b7280; --border:#e5e7eb; --brand:#111827; --link:#2563eb; }
        body { margin:0; font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:var(--bg); color:var(--text); }
        a { color: var(--link); text-decoration: none; }
        .page { min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px; }
        .wrap { width: 100%; max-width: 420px; }
        .card { background:var(--card); border:1px solid var(--border); border-radius:16px; padding:18px; box-shadow:0 1px 1px rgba(0,0,0,.03); }
        .brand { font-weight:800; letter-spacing:.2px; font-size:18px; margin-bottom:10px; }
        .muted { color: var(--muted); font-size: 13px; margin: 0 0 14px 0; }
        label { display:block; font-size:12px; color:var(--muted); margin:10px 0 4px; }
        input { width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:12px; background:white; }
        button { width:100%; background:var(--brand); color:white; border:0; padding:10px 12px; border-radius:12px; cursor:pointer; margin-top:14px; }
        .row { display:flex; justify-content:space-between; align-items:center; gap:10px; margin-top:10px; }
        .error { padding:10px 12px; border:1px solid #fecaca; background:#fef2f2; border-radius:14px; color:#991b1b; margin-bottom:12px; }
        .status { padding:10px 12px; border:1px solid #bbf7d0; background:#f0fdf4; border-radius:14px; color:#166534; margin-bottom:12px; }
    </style>
</head>
<body>
    <div class="page">
        <div class="wrap">
            <div class="brand">Academic Ops Platform</div>
            <p class="muted">Sign in to manage terms, scheduling, and syllabi.</p>

            <div class="card">
                {{ $slot }}
            </div>
        </div>
    </div>
</body>
</html>
