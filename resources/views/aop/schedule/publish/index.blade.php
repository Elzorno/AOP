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

  @if($errors->any())
    <div class="card" style="border-left:4px solid #e11d48; margin-bottom:10px;">
      <strong>Publish blocked</strong>
      <div style="margin-top:6px;" class="muted">{{ $errors->first('publish_gate') ?: $errors->first() }}</div>
    </div>
  @endif

  @if(!$term)
    <div class="card">
      <h2>No Active Term</h2>
      <p>You must set an active term before publishing schedule snapshots.</p>
    </div>
  @else
    <div class="card" style="margin-bottom:14px; border-left:4px solid {{ ($readiness && $readiness['is_ready']) ? '#16a34a' : '#d97706' }};">
      <h2 style="margin-bottom:6px;">Readiness Gate</h2>
      @if($readiness && $readiness['is_ready'])
        <p class="muted">No blockers detected. This term is ready to publish after lock verification.</p>
      @else
        <p class="muted">{{ $readiness['total_blockers'] ?? 0 }} blockers detected. Publishing is allowed only with explicit confirmation.</p>
      @endif

      @if($readiness)
        <div class="split" style="gap:16px; margin-top:10px; flex-wrap:wrap;">
          <div><span class="muted">Missing instructors:</span> <strong>{{ $readiness['metrics']['sections_missing_instructor'] ?? 0 }}</strong></div>
          <div><span class="muted">Missing meeting blocks:</span> <strong>{{ $readiness['metrics']['sections_missing_meeting_blocks'] ?? 0 }}</strong></div>
          <div><span class="muted">Missing rooms:</span> <strong>{{ $readiness['metrics']['meeting_blocks_missing_room'] ?? 0 }}</strong></div>
          <div><span class="muted">Room conflicts:</span> <strong>{{ $readiness['metrics']['room_conflicts'] ?? 0 }}</strong></div>
          <div><span class="muted">Instructor conflicts:</span> <strong>{{ $readiness['metrics']['instructor_conflicts'] ?? 0 }}</strong></div>
          <div><span class="muted">Office-hours failures:</span> <strong>{{ $readiness['metrics']['office_hours_failing'] ?? 0 }}</strong></div>
          <div><span class="muted">Instructional-minutes failures:</span> <strong>{{ $readiness['metrics']['instructional_minutes_failing'] ?? 0 }}</strong></div>
        </div>
      @endif

      <div style="margin-top:10px;" class="actions">
        <a class="btn secondary" href="{{ route('aop.schedule.readiness.index') }}">Review Readiness Details</a>
      </div>
    </div>

    <div class="card">
      <h2>Publish a New Snapshot</h2>
      <p class="muted">Publishing captures CSV exports and zip bundles at a point in time. This does not change your live schedule.</p>

      <form method="POST" action="{{ route('aop.schedule.publish.store') }}" style="margin-top:10px;">
        @csrf
        <label>Notes (optional)</label>
        <textarea name="notes" placeholder="e.g., Sent to Dean for review; labs still TBD.">{{ old('notes') }}</textarea>

        @if($readiness && !$readiness['is_ready'])
          <label style="display:flex; align-items:flex-start; gap:8px; margin-top:10px;">
            <input type="checkbox" name="confirm_publish_with_issues" value="1" {{ old('confirm_publish_with_issues') ? 'checked' : '' }}>
            <span>I understand readiness blockers are still present and I want to publish this snapshot anyway.</span>
          </label>
        @endif

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
