<x-aop-layout :activeTermLabel="'Working term: '.$term->code.' - '.$term->name">
  <x-slot:title>Open Labs</x-slot:title>

  @php
    $overLimitCount = $workloadRows->filter(fn ($row) => $row['is_over_limit'])->count();
  @endphp

  <div class="page-shell">
    <section class="page-header">
      <span class="page-eyebrow">Room Access</span>
      <h1 class="page-title">Open labs for {{ $term->code }}</h1>
      <p class="page-subtitle">Schedule staffed lab access without overlapping class use, and keep each lab tech within weekly HR limits.</p>

      <div class="summary-strip">
        <div class="summary-stat">
          <div class="summary-stat-label">Open Labs</div>
          <div class="summary-stat-value">{{ $openLabs->count() }}</div>
          <div class="summary-stat-note">Recurring lab access blocks in the active term.</div>
        </div>
        <div class="summary-stat">
          <div class="summary-stat-label">Lab Techs</div>
          <div class="summary-stat-value">{{ $workloadRows->count() }}</div>
          <div class="summary-stat-note">Active staff available for coverage.</div>
        </div>
        <div class="summary-stat">
          <div class="summary-stat-label">Over Limit</div>
          <div class="summary-stat-value">{{ $overLimitCount }}</div>
          <div class="summary-stat-note">Lab techs currently above their HR allocation.</div>
        </div>
        <div class="summary-stat">
          <div class="summary-stat-label">Schedule State</div>
          <div class="summary-stat-value">{{ $scheduleLocked ? 'Locked' : 'Editable' }}</div>
          <div class="summary-stat-note">{{ $scheduleLocked ? 'Unlock the term before changing open labs.' : 'New labs can be added or adjusted now.' }}</div>
        </div>
      </div>

      <div class="toolbar-line">
        <a class="btn" href="{{ route('aop.schedule.home', $term) }}">Back to Schedule</a>
        <a class="btn secondary" href="{{ route('aop.labtechs.index') }}">Lab Techs</a>
        <a class="btn secondary" href="{{ route('aop.schedule.readiness.index', $term) }}">Readiness</a>
        @if(!$scheduleLocked)
          <a class="btn" href="{{ route('aop.schedule.openLabs.create', $term) }}">New Open Lab</a>
        @endif
      </div>
    </section>

    <section class="insight-grid">
      <div class="insight-main">
        <section class="workspace-card">
          <div class="workspace-header">
            <div>
              <h2 class="workspace-title">Scheduled open labs</h2>
              <p class="workspace-copy">Room checks include both class meetings and other open labs.</p>
            </div>
          </div>

          <div class="table-shell">
            <table>
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Room</th>
                  <th>Lab Tech</th>
                  <th>Days</th>
                  <th>Time</th>
                  <th>Hours / Week</th>
                  <th>Notes</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                @forelse($openLabs as $openLab)
                  <tr>
                    <td><strong>{{ $openLab->name }}</strong></td>
                    <td>{{ $openLab->room?->name ?? '—' }}</td>
                    <td>{{ $openLab->labTech?->name ?? '—' }}</td>
                    <td>{{ implode(', ', $openLab->days_json ?? []) ?: '—' }}</td>
                    <td>{{ substr((string) $openLab->starts_at, 0, 5) }} - {{ substr((string) $openLab->ends_at, 0, 5) }}</td>
                    <td>{{ number_format(\App\Services\ScheduleConflictService::weeklyMinutes($openLab->days_json ?? [], (string) $openLab->starts_at, (string) $openLab->ends_at) / 60, 2) }}</td>
                    <td>{{ $openLab->notes ?: '—' }}</td>
                    <td><a class="btn secondary sm" href="{{ route('aop.schedule.openLabs.edit', [$term, $openLab]) }}">Edit</a></td>
                  </tr>
                @empty
                  <tr>
                    <td colspan="8">No open labs have been scheduled for the active term.</td>
                  </tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </section>
      </div>

      <aside class="insight-side">
        <section class="watchlist">
          <div class="briefing-kicker">Coverage</div>
          <h2 class="watchlist-title">Lab tech workload</h2>
          <p class="watchlist-copy">Weekly totals are based on the recurring open-lab schedule.</p>

          @if($workloadRows->isEmpty())
            <div class="status-note mt-5">No active lab techs yet. Add one before scheduling open labs.</div>
          @else
            <div class="mini-list">
              @foreach($workloadRows as $row)
                <div class="mini-list-item">
                  <div>
                    <div class="queue-label">{{ $row['lab_tech']->name }}</div>
                    <div class="queue-copy">
                      {{ number_format((float) $row['assigned_hours'], 2) }} / {{ number_format((float) $row['weekly_hour_limit'], 2) }} hrs ·
                      {{ $row['open_lab_count'] }} open lab{{ $row['open_lab_count'] === 1 ? '' : 's' }}
                    </div>
                  </div>
                  <span class="badge {{ $row['is_over_limit'] ? 'danger' : 'success' }}">
                    {{ $row['is_over_limit'] ? 'Over' : 'OK' }}
                  </span>
                </div>
              @endforeach
            </div>
          @endif
        </section>
      </aside>
    </section>
  </div>
</x-aop-layout>
