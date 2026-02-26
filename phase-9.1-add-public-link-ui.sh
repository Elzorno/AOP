#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

mkdir -p "$ROOT_DIR/resources/views/aop/schedule/publish"

cat > "$ROOT_DIR/resources/views/aop/schedule/publish/index.blade.php" <<'BLADE'
<x-aop-layout>
  <x-slot:title>Publish Snapshots</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>Publish Snapshots</h1>
      @if($term)
        <p style="margin-top:6px;"><strong>{{ $term->code }}</strong> — {{ $term->name }}</p>
        @if($latest)
          <p class="muted">Latest published: <span class="badge">v{{ $latest->version }}</span> {{ $latest->published_at->format('Y-m-d H:i') }}</p>
        @else
          <p class="muted">Latest published: <span class="badge">None</span></p>
        @endif
      @else
        <p class="muted">No active term is set.</p>
      @endif
    </div>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.schedule.home') }}">Back to Schedule</a>
      <a class="btn" href="{{ route('aop.schedule.reports.index') }}">Schedule Reports</a>
      @if(!$term)
        <a class="btn" href="{{ route('aop.terms.index') }}">Go to Terms</a>
      @endif
    </div>
  </div>

  @if(session('status'))
    <div class="card" style="border-left:4px solid #2ecc71;">
      <strong>{{ session('status') }}</strong>
    </div>
    <div style="height:10px;"></div>
  @endif

  @if(!$term)
    <div class="card">
      <h2>No Active Term</h2>
      <p>You must set an active term before publishing schedule snapshots.</p>
    </div>
  @else
    <div class="card">
      <h2>Publish a New Snapshot</h2>
      <p class="muted">Publishing captures CSV exports and zip bundles at a point in time. This does not change your live schedule.</p>

      <form method="POST" action="{{ route('aop.schedule.publish.store') }}" style="margin-top:10px;">
        @csrf
        <label>Notes (optional)</label>
        <textarea name="notes" placeholder="e.g., Sent to Dean for review; labs still TBD.">{{ old('notes') }}</textarea>
        <div class="actions" style="margin-top:10px;">
          <button class="btn" type="submit">Publish Snapshot</button>
        </div>
      </form>
    </div>

    <div style="height:14px;"></div>

    <div class="card">
      <h2>Published Versions</h2>
      @if($publications->count() === 0)
        <p class="muted">No snapshots have been published for this term yet.</p>
      @else
        <table style="margin-top:8px;">
          <thead>
            <tr>
              <th style="width:90px;">Version</th>
              <th style="width:170px;">Published</th>
              <th style="width:180px;">By</th>
              <th>Notes</th>
              <th style="width:300px;">Public Link</th>
              <th style="width:260px;">Downloads</th>
            </tr>
          </thead>
          <tbody>
            @foreach($publications as $p)
              @php
                $publicUrl = null;
                if ($p->public_token) {
                  $publicUrl = route('public.schedule.show', [
                    'termCode' => $p->term?->code ?? $term->code,
                    'version' => $p->version,
                    'token' => $p->public_token,
                  ]);
                }
              @endphp
              <tr>
                <td><span class="badge">v{{ $p->version }}</span></td>
                <td>{{ $p->published_at->format('Y-m-d H:i') }}</td>
                <td>{{ $p->publishedBy?->name ?? 'Unknown' }}</td>
                <td class="muted">{{ $p->notes ?? '' }}</td>

                <td>
                  @if($publicUrl)
                    <div style="display:flex; gap:8px; align-items:center;">
                      <a class="btn secondary" href="{{ $publicUrl }}" target="_blank" rel="noopener">Open</a>
                      <button class="btn secondary" type="button" onclick="copyPublicLink('pub_{{ $p->id }}')">Copy</button>
                    </div>
                    <div style="margin-top:6px;">
                      <input id="pub_{{ $p->id }}" type="text" readonly value="{{ $publicUrl }}" style="width:100%; font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace; font-size:12px;">
                    </div>
                    <div class="muted" style="margin-top:4px; font-size:12px;">Anyone with this link can view/download the published snapshot.</div>
                  @else
                    <span class="muted">Not available</span>
                  @endif
                </td>

                <td>
                  <div class="actions" style="gap:8px;">
                    <a class="btn" href="{{ route('aop.schedule.publish.downloadTerm', $p) }}">Term CSV</a>
                    <a class="btn secondary" href="{{ route('aop.schedule.publish.downloadInstructorsZip', $p) }}">Instructors ZIP</a>
                    <a class="btn secondary" href="{{ route('aop.schedule.publish.downloadRoomsZip', $p) }}">Rooms ZIP</a>
                  </div>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>

        <script>
          function copyPublicLink(inputId) {
            const el = document.getElementById(inputId);
            if (!el) return;
            el.select();
            el.setSelectionRange(0, 99999);
            if (navigator.clipboard && navigator.clipboard.writeText) {
              navigator.clipboard.writeText(el.value).catch(() => document.execCommand('copy'));
            } else {
              document.execCommand('copy');
            }
          }
        </script>
      @endif
    </div>
  @endif
</x-aop-layout>
BLADE

chown www-data:www-data "$ROOT_DIR/resources/views/aop/schedule/publish/index.blade.php"
chmod 644 "$ROOT_DIR/resources/views/aop/schedule/publish/index.blade.php"

echo "OK: Phase 9.1 applied (Public Link column/buttons added)."
