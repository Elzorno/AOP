@php
    $title = 'Published Schedule';
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — {{ $term->code }} v{{ $publication->version }}</title>
    <style>
        body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:0;padding:0;background:#0b1220;color:#e5e7eb}
        .wrap{max-width:980px;margin:0 auto;padding:24px}
        .card{background:#111827;border:1px solid #1f2937;border-radius:12px;padding:18px}
        .row{display:flex;gap:12px;flex-wrap:wrap}
        .btn{display:inline-block;text-decoration:none;padding:10px 12px;border-radius:10px;border:1px solid #374151;background:#0b1220;color:#e5e7eb}
        .btn:hover{background:#0f172a}
        .muted{color:#9ca3af}
        h1{font-size:22px;margin:0 0 10px}
        h2{font-size:16px;margin:16px 0 8px}
        .badge{display:inline-block;font-size:12px;padding:3px 8px;border-radius:999px;background:#065f46;color:#d1fae5;border:1px solid #047857}
        .meta{font-size:13px}
        .sep{height:1px;background:#1f2937;margin:14px 0}
        pre{white-space:pre-wrap;background:#0b1220;border:1px solid #1f2937;border-radius:10px;padding:10px}
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="row" style="justify-content:space-between;align-items:center">
            <div>
                <h1>Published Schedule</h1>
                <div class="meta muted">
                    Term <strong>{{ $term->code }}</strong> · Version <strong>v{{ $publication->version }}</strong>
                    · <span class="badge">Published</span>
                </div>
                <div class="meta muted" style="margin-top:6px">
                    Published at: {{ $publication->published_at?->format('Y-m-d H:i') ?? '—' }}
                </div>
            </div>
        </div>

        @if($publication->notes)
            <div class="sep"></div>
            <h2>Notes</h2>
            <pre>{{ $publication->notes }}</pre>
        @endif

        <div class="sep"></div>
        <h2>Downloads</h2>
        <div class="row">
            <a class="btn" href="{{ $downloads['term'] }}">Download Term CSV</a>
            <a class="btn" href="{{ $downloads['instructors_zip'] }}">Download Instructors ZIP</a>
            <a class="btn" href="{{ $downloads['rooms_zip'] }}">Download Rooms ZIP</a>
        </div>

        <div class="sep"></div>
        <div class="muted meta">
            This page is a read-only view of a published snapshot. If you need changes, contact the scheduler/admin.
        </div>
    </div>
</div>
</body>
</html>
