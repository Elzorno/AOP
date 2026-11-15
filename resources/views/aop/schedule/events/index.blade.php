<x-aop-layout :activeTermLabel="'Active Term: '.$term->code.' - '.$term->name">
  <x-slot:title>Scheduled Events</x-slot:title>

  <div class="page-shell">
    <section class="page-header">
      <span class="page-eyebrow">Schedule Core</span>
      <h1 class="page-title">Scheduled events for {{ $term->code }}</h1>
      <p class="page-subtitle">Classes and open labs share the same room, time, and staffing rules.</p>
      <div class="toolbar-line">
        <a class="btn secondary" href="{{ route('aop.schedule.home', $term) }}">Schedule Studio</a>
        @if(!$scheduleLocked)
          <a class="btn" href="{{ route('aop.schedule.events.create', $term) }}">New Scheduled Event</a>
        @endif
      </div>
    </section>

    <section class="workspace-card">
      <div class="table-shell">
        <table>
          <thead><tr><th>Type</th><th>Event</th><th>Room</th><th>Days</th><th>Time</th><th>Notes</th><th>Manage</th></tr></thead>
          <tbody>
            @forelse($events as $event)
              <tr>
                <td><span class="badge {{ $event->event_type === 'open_lab' ? 'warn' : 'success' }}">{{ $event->event_type === 'open_lab' ? 'Open Lab' : 'Class' }}</span></td>
                <td><strong>{{ $event->display_name }}</strong><div class="muted">{{ $event->category ?: '—' }}</div></td>
                <td>{{ $event->room?->name ?? 'Online / TBD' }}</td>
                <td>{{ implode(', ', $event->days_json ?? []) }}</td>
                <td>{{ substr((string) $event->starts_at, 0, 5) }} - {{ substr((string) $event->ends_at, 0, 5) }}</td>
                <td>{{ $event->notes ?: '—' }}</td>
                <td>
                  @if($event->source_type === \App\Models\OpenLab::class)
                    <a class="btn secondary sm" href="{{ route('aop.schedule.openLabs.edit', [$term, $event->source_id]) }}">Edit</a>
                  @elseif($event->source_type === \App\Models\MeetingBlock::class)
                    @php($sectionId = $event->source?->section_id)
                    @if($sectionId)<a class="btn secondary sm" href="{{ route('aop.schedule.sections.edit', [$term, $sectionId]) }}">Edit</a>@endif
                  @endif
                </td>
              </tr>
            @empty
              <tr><td colspan="7">No events are scheduled for this term.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </section>
  </div>
</x-aop-layout>
