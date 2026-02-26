<x-aop-layout :activeTermLabel="('Active Term: '.$term->code.' — '.$term->name)">
  <x-slot:title>Schedule Readiness</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>Schedule Readiness</h1>
      <p class="muted" style="margin-top:6px;">Active term: <strong>{{ $term->code }}</strong> — {{ $term->name }}</p>
    </div>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.schedule.home') }}">Back</a>
    </div>
  </div>

  {{-- Instructional Minutes --}}
  <div class="card" style="margin-bottom:16px;">
    <h2>Instructional Minutes (ODHE / SSU Rules)</h2>
    <p class="muted">
      Required minutes are computed from weekly contact hours:
      <strong>(lecture_contact_hours × 750) + ((lab_contact_hours ÷ 3) × 2250)</strong>.
      Term weeks: <strong>{{ $term->weeks_in_term ?? 15 }}</strong> (scaled from a 15-week baseline).
    </p>

    <table class="table">
      <thead>
        <tr>
          <th>Status</th>
          <th>Course / Section</th>
          <th>Lecture/Lab (hrs)</th>
          <th>Required</th>
          <th>Scheduled</th>
          <th>Delta</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        @forelse($instructionalMinutes as $row)
          @php
            $s = $row['section'];
            $course = $row['course'];
            $required = $row['required_minutes'];
            $scheduled = $row['scheduled_minutes'];
            $delta = $row['delta_minutes'];
            $pass = $row['pass'];
            $lec = $row['lecture_contact_hours'] ?? 0;
            $lab = $row['lab_contact_hours'] ?? 0;
          @endphp
          <tr>
            <td>
              @if($pass)
                <span class="badge" style="background:#dcfce7;color:#14532d;">PASS</span>
              @else
                <span class="badge" style="background:#fee2e2;color:#7f1d1d;">FAIL</span>
              @endif
            </td>
            <td>
              <div>
                <strong>{{ $course?->code ?? '—' }}</strong> — {{ $course?->title ?? 'Untitled' }}<br>
                Section {{ $s->section_code }} • Modality: {{ $s->modality?->value ?? '—' }}
              </div>
            </td>
            <td>{{ number_format($lec, 2) }} / {{ number_format($lab, 2) }}</td>
            <td>{{ number_format($required) }} min</td>
            <td>{{ number_format($scheduled) }} min</td>
            <td>
              @if($delta >= 0)
                <span class="badge" style="background:#dcfce7;color:#14532d;">+{{ number_format($delta) }} min</span>
              @else
                <span class="badge" style="background:#fee2e2;color:#7f1d1d;">{{ number_format($delta) }} min</span>
              @endif
            </td>
            <td>
              <a class="btn" href="{{ route('aop.schedule.sections.edit', $s) }}">Edit</a>
            </td>
          </tr>
        @empty
          <tr><td colspan="7" class="muted">No sections found for the active term.</td></tr>
        @endforelse
      </tbody>
    </table>

    @if(($minutesFailing ?? collect())->count() > 0)
      <div class="muted" style="margin-top:10px;">
        <strong>{{ ($minutesFailing ?? collect())->count() }}</strong> section(s) are failing the instructional minutes requirement.
      </div>
    @else
      <div class="muted" style="margin-top:10px;">
        All sections are meeting the instructional minutes requirement.
      </div>
    @endif
  </div>

  {{-- Existing checks remain below (instructor, blocks, rooms, conflicts) --}}

  <div class="grid">
    <div class="card col-6">
      <h2>Missing Instructor</h2>
      @if($sectionsMissingInstructor->count() === 0)
        <p class="muted">All sections have an instructor.</p>
      @else
        <ul>
          @foreach($sectionsMissingInstructor as $s)
            <li>
              {{ $s->offering?->catalogCourse?->code ?? '—' }} {{ $s->section_code }}
              <a href="{{ route('aop.schedule.sections.edit', $s) }}">Edit</a>
            </li>
          @endforeach
        </ul>
      @endif
    </div>

    <div class="card col-6">
      <h2>Missing Meeting Blocks</h2>
      @if($sectionsMissingMeetingBlocks->count() === 0)
        <p class="muted">All sections have meeting blocks.</p>
      @else
        <ul>
          @foreach($sectionsMissingMeetingBlocks as $s)
            <li>
              {{ $s->offering?->catalogCourse?->code ?? '—' }} {{ $s->section_code }}
              <a href="{{ route('aop.schedule.sections.edit', $s) }}">Edit</a>
            </li>
          @endforeach
        </ul>
      @endif
    </div>

    <div class="card col-6">
      <h2>Meeting Blocks Missing Room</h2>
      @if($meetingBlocksMissingRoom->count() === 0)
        <p class="muted">All meeting blocks have rooms (or are online).</p>
      @else
        <ul>
          @foreach($meetingBlocksMissingRoom as $mb)
            <li>
              {{ $mb->section?->offering?->catalogCourse?->code ?? '—' }} {{ $mb->section?->section_code ?? '' }} — {{ $mb->type }}
              <a href="{{ route('aop.schedule.sections.edit', $mb->section) }}">Edit</a>
            </li>
          @endforeach
        </ul>
      @endif
    </div>

    <div class="card col-6">
      <h2>Room Conflicts</h2>
      <p class="muted" style="margin-top:4px;">Buffer minutes applied: <strong>{{ (int)($term->buffer_minutes ?? 0) }}</strong> min</p>
      @if(count($roomConflicts) === 0)
        <p class="muted">No room conflicts detected.</p>
      @else
        <ul>
          @foreach($roomConflicts as $c)
            <li>
              <strong>{{ $c['room']?->name ?? 'Room' }}</strong>: {{ \App\Services\ScheduleConflictService::formatMeetingBlockLabel($c['a']) }}
              vs {{ \App\Services\ScheduleConflictService::formatMeetingBlockLabel($c['b']) }}
              <span class="muted">(buffer {{ (int)($c['buffer_minutes'] ?? ($term->buffer_minutes ?? 0)) }}m)</span>
            </li>
          @endforeach
        </ul>
      @endif
    </div>

    <div class="card col-12">
      <h2>Instructor Conflicts</h2>
      <p class="muted" style="margin-top:4px;">Buffer minutes applied: <strong>{{ (int)($term->buffer_minutes ?? 0) }}</strong> min</p>
      @if(count($instructorConflicts) === 0)
        <p class="muted">No instructor conflicts detected.</p>
      @else
        <ul>
          @foreach($instructorConflicts as $c)
            <li>
              <strong>{{ $c['instructor']?->name ?? 'Instructor' }}</strong> ({{ $c['type'] }}):
              {{ $c['a_label'] }} vs {{ $c['b_label'] }}
              <span class="muted">(buffer {{ (int)($c['buffer_minutes'] ?? ($term->buffer_minutes ?? 0)) }}m)</span>
              @if($c['a_section_id'])
                <a href="{{ route('aop.schedule.sections.edit', $c['a_section_id']) }}">Edit A</a>
              @endif
              @if($c['b_section_id'])
                <a href="{{ route('aop.schedule.sections.edit', $c['b_section_id']) }}">Edit B</a>
              @endif
            </li>
          @endforeach
        </ul>
      @endif
    </div>
  </div>
</x-aop-layout>
