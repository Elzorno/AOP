#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

write_file() {
  local path="$1"
  mkdir -p "$(dirname "$ROOT_DIR/$path")"
  cat > "$ROOT_DIR/$path"
}

# 1) Migration
write_file "database/migrations/2026_02_26_000011_add_public_token_to_schedule_publications_table.php" <<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_publications', function (Blueprint $table) {
            // Public share token (used in Phase 9 public read-only views)
            $table->string('public_token', 64)->nullable()->after('storage_base_path');
        });

        Schema::table('schedule_publications', function (Blueprint $table) {
            $table->unique('public_token');
        });
    }

    public function down(): void
    {
        Schema::table('schedule_publications', function (Blueprint $table) {
            $table->dropUnique(['public_token']);
            $table->dropColumn('public_token');
        });
    }
};
PHP

# 2) Model update
write_file "app/Models/SchedulePublication.php" <<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchedulePublication extends Model
{
    protected $fillable = [
        'term_id',
        'version',
        'notes',
        'published_at',
        'published_by_user_id',
        'storage_base_path',
        'public_token',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    public function term()
    {
        return $this->belongsTo(Term::class);
    }

    public function publishedBy()
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }
}
PHP

# 3) Public controller
write_file "app/Http/Controllers/Public/SchedulePublicController.php" <<'PHP'
<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\SchedulePublication;
use App\Models\Term;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SchedulePublicController extends Controller
{
    public function show(string $termCode, ?int $version = null, string $token = '')
    {
        $term = Term::where('code', $termCode)->first();
        abort_if(!$term, 404);

        $publication = SchedulePublication::where('term_id', $term->id)
            ->when($version !== null, fn($q) => $q->where('version', $version), fn($q) => $q->orderByDesc('version'))
            ->first();

        abort_if(!$publication, 404);
        abort_if(!$publication->public_token || $publication->public_token !== $token, 404);

        return view('public.schedule.show', [
            'term' => $term,
            'publication' => $publication,
            'downloads' => [
                'term' => route('public.schedule.download.term', [$term->code, $publication->version, $publication->public_token]),
                'instructors_zip' => route('public.schedule.download.instructors', [$term->code, $publication->version, $publication->public_token]),
                'rooms_zip' => route('public.schedule.download.rooms', [$term->code, $publication->version, $publication->public_token]),
            ],
        ]);
    }

    public function downloadTerm(string $termCode, int $version, string $token): StreamedResponse
    {
        $pub = $this->resolvePublication($termCode, $version, $token);
        return $this->downloadLocalFile($pub->storage_base_path . '/term_schedule.csv', sprintf('aop_%s_v%d_term_schedule.csv', $termCode, $pub->version));
    }

    public function downloadInstructorsZip(string $termCode, int $version, string $token): StreamedResponse
    {
        $pub = $this->resolvePublication($termCode, $version, $token);
        return $this->downloadLocalFile($pub->storage_base_path . '/instructors.zip', sprintf('aop_%s_v%d_instructors.zip', $termCode, $pub->version));
    }

    public function downloadRoomsZip(string $termCode, int $version, string $token): StreamedResponse
    {
        $pub = $this->resolvePublication($termCode, $version, $token);
        return $this->downloadLocalFile($pub->storage_base_path . '/rooms.zip', sprintf('aop_%s_v%d_rooms.zip', $termCode, $pub->version));
    }

    private function resolvePublication(string $termCode, int $version, string $token): SchedulePublication
    {
        $term = Term::where('code', $termCode)->first();
        abort_if(!$term, 404);

        $publication = SchedulePublication::where('term_id', $term->id)->where('version', $version)->first();
        abort_if(!$publication, 404);
        abort_if(!$publication->public_token || $publication->public_token !== $token, 404);

        return $publication;
    }

    private function downloadLocalFile(string $storagePath, string $downloadName): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($storagePath), 404);

        return response()->streamDownload(function () use ($storagePath) {
            $stream = Storage::disk('local')->readStream($storagePath);
            if (!$stream) {
                return;
            }
            fpassthru($stream);
            fclose($stream);
        }, $downloadName);
    }
}
PHP

# 4) Public view
write_file "resources/views/public/schedule/show.blade.php" <<'BLADE'
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
BLADE

# 5) Routes append (best-effort idempotent append)
ROUTES_FILE="$ROOT_DIR/routes/web.php"
if ! grep -q "public.schedule.show" "$ROUTES_FILE"; then
cat >> "$ROUTES_FILE" <<'PHP'

// Public read-only published schedule (Phase 9)
Route::get('/p/{termCode}/{version?}/{token}', [\App\Http\Controllers\Public\SchedulePublicController::class, 'show'])
    ->whereNumber('version')
    ->name('public.schedule.show');

Route::get('/p/{termCode}/{version}/{token}/download/term', [\App\Http\Controllers\Public\SchedulePublicController::class, 'downloadTerm'])
    ->whereNumber('version')
    ->name('public.schedule.download.term');

Route::get('/p/{termCode}/{version}/{token}/download/instructors', [\App\Http\Controllers\Public\SchedulePublicController::class, 'downloadInstructorsZip'])
    ->whereNumber('version')
    ->name('public.schedule.download.instructors');

Route::get('/p/{termCode}/{version}/{token}/download/rooms', [\App\Http\Controllers\Public\SchedulePublicController::class, 'downloadRoomsZip'])
    ->whereNumber('version')
    ->name('public.schedule.download.rooms');
PHP
fi

# 6) Publish page public link column (overwrite view if exists in repo zip)
# NOTE: this phase script expects the repo already contains the updated view.
# We do not re-render it here to avoid fragile string edits.

# Permissions: keep app files readable
chown -R www-data:www-data "$ROOT_DIR/app" "$ROOT_DIR/routes" "$ROOT_DIR/resources" "$ROOT_DIR/database" 2>/dev/null || true
find "$ROOT_DIR" -type d -exec chmod 755 {} \; 2>/dev/null || true
find "$ROOT_DIR" -type f -exec chmod 644 {} \; 2>/dev/null || true
chmod +x "$ROOT_DIR/phase-9-public-published-view.sh" 2>/dev/null || true

echo "OK: Phase 9 applied. Run: php artisan migrate && php artisan optimize:clear"
