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
        header { background:var(--card); border-bottom:1px solid var(--border); padding:14px 20px; display:flex; justify-content:space-between; align-items:center; gap:12px; }
        main { max-width:1100px; margin:18px auto; padding:0 16px; }
        .brand { font-weight:800; letter-spacing:.2px; }
        .card { background:var(--card); border:1px solid var(--border); border-radius:16px; padding:16px; box-shadow:0 1px 1px rgba(0,0,0,.03); }
        .row { display:flex; gap:12px; flex-wrap:wrap; align-items:center; }
        .nav a { padding:6px 10px; border-radius:10px; color:var(--text); }
        .nav a.active { background:#eef2ff; color:#1d4ed8; }
        .btn { background:var(--brand); color:white; border:0; padding:8px 12px; border-radius:12px; cursor:pointer; }
    </style>
</head>
<body>
    <header>
        <div class="row">
            <div class="brand">Academic Ops Platform</div>
        </div>
        <div class="row nav">
            <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">Dashboard</a>
            <a href="{{ route('profile.edit') }}" class="{{ request()->routeIs('profile.*') ? 'active' : '' }}">Profile</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="btn" type="submit">Log out</button>
            </form>
        </div>
    </header>

    <main>
        {{ $slot }}
    </main>
</body>
</html>
