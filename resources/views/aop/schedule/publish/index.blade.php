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
              <th style="width:260px;">Downloads</th>
            </tr>
          </thead>
          <tbody>
            @foreach($publications as $p)
              <tr>
                <td><span class="badge">v{{ $p->version }}</span></td>
                <td>{{ $p->published_at->format('Y-m-d H:i') }}</td>
                <td>{{ $p->publishedBy?->name ?? 'Unknown' }}</td>
                <td class="muted">{{ $p->notes ?? '' }}</td>
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
      @endif
    </div>
  @endif
</x-aop-layout>
