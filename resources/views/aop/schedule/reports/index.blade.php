<x-aop-layout>
  <x-slot:title>Schedule Reports</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>Schedule Reports</h1>
      @if($term)
        <p style="margin-top:6px;"><strong>{{ $term->code }}</strong> — {{ $term->name }}</p>
        <p class="muted">Exports are scoped to the active term.</p>
      @else
        <p class="muted">No active term is set.</p>
      @endif
    </div>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.schedule.home') }}">Back to Schedule</a>
      @if(!$term)
        <a class="btn" href="{{ route('aop.terms.index') }}">Go to Terms</a>
      @endif
    </div>
  </div>

  @if(!$term)
    <div class="card">
      <h2>No Active Term</h2>
      <p>You must set an active term before schedule reports and exports can be generated.</p>
    </div>
  @else
    <div class="grid" style="display:grid; grid-template-columns: 1fr 1fr; gap: 14px;">
      <div class="card">
        <h2>Overview</h2>
        <div class="muted" style="margin-top:8px;">
          <div><strong>Offerings:</strong> {{ $stats['offerings'] ?? 0 }}</div>
          <div><strong>Sections:</strong> {{ $stats['sections'] ?? 0 }}</div>
          <div><strong>Meeting Blocks:</strong> {{ $stats['meeting_blocks'] ?? 0 }}</div>
          <div><strong>Office Hours Blocks:</strong> {{ $stats['office_hours_blocks'] ?? 0 }}</div>
        </div>

        <div style="margin-top:12px;">
          <a class="btn" href="{{ route('aop.schedule.reports.exportTerm') }}">Export Term Schedule (CSV)</a>
        </div>
      </div>

      <div class="card">
        <h2>Quick Links</h2>
        <p class="muted">Jump to grids, then use Print if needed.</p>
        <div class="actions" style="margin-top:10px; flex-wrap:wrap;">
          <a class="btn" href="{{ route('aop.schedule.grids.index') }}">Schedule Grids</a>
          <a class="btn" href="{{ route('aop.schedule.sections.index') }}">Sections</a>
          <a class="btn" href="{{ route('aop.schedule.officeHours.index') }}">Office Hours</a>
        </div>
      </div>
    </div>

    <div class="grid" style="display:grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top:14px;">
      <div class="card">
        <h2>Export Instructor Schedule</h2>
        <p class="muted">Includes classes + office hours.</p>
        <div class="row" style="gap:10px; align-items:flex-end;">
          <div style="flex:1;">
            <label class="label">Instructor</label>
            <select class="input" id="instructorSelect">
              <option value="">Select...</option>
              @foreach($instructors as $ins)
                <option value="{{ $ins->id }}">{{ $ins->name }}</option>
              @endforeach
            </select>
          </div>
          <div>
            <button class="btn" type="button" onclick="
              const id=document.getElementById('instructorSelect').value;
              if(!id){ alert('Select an instructor.'); return; }
              window.location='{{ url('/aop/schedule/reports/export/instructors') }}/'+id;
            ">Download CSV</button>
          </div>
        </div>
      </div>

      <div class="card">
        <h2>Export Room Schedule</h2>
        <p class="muted">Includes classes only (office hours excluded).</p>
        <div class="row" style="gap:10px; align-items:flex-end;">
          <div style="flex:1;">
            <label class="label">Room</label>
            <select class="input" id="roomSelect">
              <option value="">Select...</option>
              @foreach($rooms as $r)
                <option value="{{ $r->id }}">{{ $r->name }}</option>
              @endforeach
            </select>
          </div>
          <div>
            <button class="btn" type="button" onclick="
              const id=document.getElementById('roomSelect').value;
              if(!id){ alert('Select a room.'); return; }
              window.location='{{ url('/aop/schedule/reports/export/rooms') }}/'+id;
            ">Download CSV</button>
          </div>
        </div>
      </div>
    </div>

    <div class="grid" style="display:grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top:14px;">
      <div class="card">
        <h2>Unassigned</h2>

        <details open style="margin-top:8px;">
          <summary style="cursor:pointer; font-weight:600;">Sections Missing Instructor ({{ $unassigned['sections_missing_instructor']->count() }})</summary>
          <div style="margin-top:8px;">
            @if($unassigned['sections_missing_instructor']->count() === 0)
              <div class="muted">None</div>
            @else
              <ul>
                @foreach($unassigned['sections_missing_instructor'] as $s)
                  <li>
                    {{ $s->offering->catalogCourse->code }} {{ $s->section_code }}
                    <span class="muted"> — {{ $s->offering->catalogCourse->title }}</span>
                  </li>
                @endforeach
              </ul>
            @endif
          </div>
        </details>

        <details style="margin-top:10px;">
          <summary style="cursor:pointer; font-weight:600;">Sections Missing Meeting Blocks ({{ $unassigned['sections_missing_meeting_blocks']->count() }})</summary>
          <div style="margin-top:8px;">
            @if($unassigned['sections_missing_meeting_blocks']->count() === 0)
              <div class="muted">None</div>
            @else
              <ul>
                @foreach($unassigned['sections_missing_meeting_blocks'] as $s)
                  <li>
                    {{ $s->offering->catalogCourse->code }} {{ $s->section_code }}
                    <span class="muted"> — {{ $s->instructor?->name ?? 'TBD Instructor' }}</span>
                  </li>
                @endforeach
              </ul>
            @endif
          </div>
        </details>

        <details style="margin-top:10px;">
          <summary style="cursor:pointer; font-weight:600;">Meeting Blocks Missing Room ({{ $unassigned['meeting_blocks_missing_room']->count() }})</summary>
          <div style="margin-top:8px;">
            @if($unassigned['meeting_blocks_missing_room']->count() === 0)
              <div class="muted">None</div>
            @else
              <ul>
                @foreach($unassigned['meeting_blocks_missing_room'] as $mb)
                  <li>
                    {{ $mb->section->offering->catalogCourse->code }} {{ $mb->section->section_code }}
                    <span class="muted"> — {{ $mb->section->instructor?->name ?? 'TBD Instructor' }}</span>
                    <span class="muted"> ({{ substr((string)$mb->starts_at,0,5) }}–{{ substr((string)$mb->ends_at,0,5) }})</span>
                  </li>
                @endforeach
              </ul>
            @endif
          </div>
        </details>
      </div>

      <div class="card">
        <h2>Counts</h2>

        <div style="margin-top:10px;">
          <h3 style="margin:0 0 6px 0;">By Modality</h3>
          @if(empty($stats['modalities']))
            <div class="muted">No data</div>
          @else
            <ul>
              @foreach($stats['modalities'] as $k => $v)
                <li>{{ $k }}: <strong>{{ $v }}</strong></li>
              @endforeach
            </ul>
          @endif
        </div>

        <div style="margin-top:14px;">
          <h3 style="margin:0 0 6px 0;">By Meeting Type</h3>
          @if(empty($stats['meeting_types']))
            <div class="muted">No data</div>
          @else
            <ul>
              @foreach($stats['meeting_types'] as $k => $v)
                <li>{{ $k }}: <strong>{{ $v }}</strong></li>
              @endforeach
            </ul>
          @endif
        </div>
      </div>
    </div>
  @endif
</x-aop-layout>
