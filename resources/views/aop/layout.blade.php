<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>{{ $title ?? 'Academic Ops Platform' }}</title>
  <style>
    :root { --bg:#f7f7f8; --card:#ffffff; --text:#111827; --muted:#6b7280; --border:#e5e7eb; --brand:#111827; --link:#2563eb; }
    body { margin:0; font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:var(--bg); color:var(--text); }
    header { background:var(--card); border-bottom:1px solid var(--border); padding:14px 20px; display:flex; gap:16px; align-items:center; justify-content:space-between; }
    .brand { font-weight:700; letter-spacing:.2px; }
    .nav { display:flex; gap:12px; flex-wrap:wrap; }
    .nav a { text-decoration:none; color:var(--text); padding:6px 10px; border-radius:10px; }
    .nav a.active { background:#eef2ff; color:#1d4ed8; }
    main { max-width:1100px; margin:18px auto; padding:0 16px; }
    .row { display:flex; gap:14px; flex-wrap:wrap; align-items:center; justify-content:space-between; }
    .card { background:var(--card); border:1px solid var(--border); border-radius:16px; padding:16px; box-shadow:0 1px 1px rgba(0,0,0,.03); }
    .grid { display:grid; grid-template-columns: repeat(12, 1fr); gap:14px; }
    .col-4 { grid-column: span 4; }
    .col-6 { grid-column: span 6; }
    .col-12 { grid-column: span 12; }
    @media (max-width: 900px){ .col-4,.col-6{ grid-column: span 12; } }
    h1 { font-size:22px; margin:0; }
    h2 { font-size:16px; margin:0 0 10px 0; }
    p { margin:6px 0; color:var(--muted); }
    table { width:100%; border-collapse:collapse; }
    th, td { text-align:left; padding:10px; border-bottom:1px solid var(--border); vertical-align:top; }
    th { font-size:12px; color:var(--muted); text-transform:uppercase; letter-spacing:.06em; }
    .btn { display:inline-block; background:var(--brand); color:white; padding:8px 12px; border-radius:12px; text-decoration:none; border:0; cursor:pointer; }
    .btn.secondary { background:#374151; }
    .btn.link { background:transparent; color:var(--link); padding:0; }
    .badge { display:inline-block; padding:3px 8px; border-radius:999px; font-size:12px; background:#eef2ff; color:#1d4ed8; }
    .status { padding:10px 12px; border:1px solid #bbf7d0; background:#f0fdf4; border-radius:14px; color:#166534; }
    .error { padding:10px 12px; border:1px solid #fecaca; background:#fef2f2; border-radius:14px; color:#991b1b; }
    label { display:block; font-size:12px; color:var(--muted); margin:10px 0 4px; }
    input, textarea, select { width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:12px; background:white; }
    textarea { min-height:120px; }
    .actions { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
    footer { max-width:1100px; margin:24px auto; padding:0 16px 24px; color:var(--muted); font-size:12px; }
    .split { display:flex; gap:12px; flex-wrap:wrap; }
    .split > * { flex:1 1 260px; }
  </style>
</head>
<body>
<header>
  <div class="row" style="width:100%">
    <div class="split">
      <div>
        <div class="brand">Academic Ops Platform</div>
        <div style="margin-top:4px; color:var(--muted); font-size:12px;">
          {{ $activeTermLabel ?? 'No active term selected' }}
        </div>
      </div>
      <div style="text-align:right;">
        <div style="font-size:12px; color:var(--muted);">Signed in as</div>
        <div style="font-weight:600;">{{ auth()->user()->name }}</div>
      </div>
    </div>

    <nav class="nav" style="margin-top:10px;">
      <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">Dashboard</a>
      <a href="{{ route('aop.terms.index') }}" class="{{ request()->routeIs('aop.terms.*') ? 'active' : '' }}">Terms</a>
      <a href="{{ route('aop.instructors.index') }}" class="{{ request()->routeIs('aop.instructors.*') ? 'active' : '' }}">Instructors</a>
      <a href="{{ route('aop.rooms.index') }}" class="{{ request()->routeIs('aop.rooms.*') ? 'active' : '' }}">Rooms</a>
      <a href="{{ route('aop.catalog.index') }}" class="{{ request()->routeIs('aop.catalog.*') ? 'active' : '' }}">Catalog</a>
      <a href="{{ route('profile.edit') }}" class="{{ request()->routeIs('profile.*') ? 'active' : '' }}">Profile</a>
      <form method="POST" action="{{ route('logout') }}" style="display:inline;">
        @csrf
        <button class="btn secondary" type="submit">Log out</button>
      </form>
    </nav>
  </div>
</header>

<main>
  @if (session('status'))
    <div class="status">{{ session('status') }}</div>
    <div style="height:12px;"></div>
  @endif

  @if ($errors->any())
    <div class="error">
      <div style="font-weight:700; margin-bottom:6px;">Please fix the following:</div>
      <ul style="margin:0; padding-left:18px;">
        @foreach ($errors->all() as $e)
          <li>{{ $e }}</li>
        @endforeach
      </ul>
    </div>
    <div style="height:12px;"></div>
  @endif

  {{ $slot }}
</main>

<footer>
  <div>Version: <span class="badge">{{ config('aop.version', 'dev') }}</span></div>
</footer>
</body>
</html>
