<x-aop-layout>
  <x-slot:title>Schedule Readiness</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>Schedule Readiness</h1>
      <p class="muted" style="margin-top:6px;"><strong>{{ $term->code }}</strong> — {{ $term->name }}</p>
      @if($term->schedule_locked)
        <p class="muted">Schedule lock: <span class="badge">Locked</span></p>
      @else
        <p class="muted">Schedule lock: <span class="badge">Unlocked</span></p>
      @endif
    </div>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.schedule.home') }}">Back to Schedule</a>
      <a class="btn" href="{{ route('aop.schedule.publish.index') }}">Publish Snapshots</a>
    </div>
  </div>

  <div class="card">
    <h2>Completeness</h2>

    <table>
      <thead>
        <tr>
          <th style="width:260px;">Check</th>
          <th style="width:120px;">Count</th>
          <th>Details</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>Sections missing instructor</td>
          <td><span class="badge">{{ $sectionsMissingInstructor->count() }}</span></td>
          <td class="muted">Assign an instructor on the Section edit page.</td>
        </tr>
        <tr>
          <td>Sections missing meeting blocks</td>
          <td><span class="badge">{{ $sectionsMissingMeetingBlocks->count() }}</span></td>
          <td class="muted">Add at least one meeting block per section.</td>
        </tr>
        <tr>
          <td>Meeting blocks missing room</td>
          <td><span class="badge">{{ $meetingBlocksMissingRoom->count() }}</span></td>
          <td class="muted">Rooms are required for in-person/hybrid meeting blocks.</td>
        </tr>
      </tbody>
    </table>
  </div>

  <div style="height:14px;"></div>

  <div class="card">
    <h2>Missing Instructor</h2>
    @if($sectionsMissingInstructor->count() === 0)
      <p class="muted">None.</p>
    @else
      <table>
        <thead>
          <tr>
            <th>Course</th>
            <th>Section</th>
            <th>Modality</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          @foreach($sectionsMissingInstructor as $s)
            <tr>
              <td>{{ $s->offering->catalogCourse->code }} — {{ $s->offering->catalogCourse->title }}</td>
              <td>{{ $s->section_code }}</td>
              <td><span class="badge">{{ $s->modality->value }}</span></td>
              <td><a class="btn secondary" href="{{ route('aop.schedule.sections.edit', $s) }}">Edit Section</a></td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>

  <div style="height:14px;"></div>

  <div class="card">
    <h2>Missing Meeting Blocks</h2>
    @if($sectionsMissingMeetingBlocks->count() === 0)
      <p class="muted">None.</p>
    @else
      <table>
        <thead>
          <tr>
            <th>Course</th>
            <th>Section</th>
            <th>Instructor</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          @foreach($sectionsMissingMeetingBlocks as $s)
            <tr>
              <td>{{ $s->offering->catalogCourse->code }} — {{ $s->offering->catalogCourse->title }}</td>
              <td>{{ $s->section_code }}</td>
              <td>{{ $s->instructor?->name ?? '—' }}</td>
              <td><a class="btn secondary" href="{{ route('aop.schedule.sections.edit', $s) }}">Edit Section</a></td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>

  <div style="height:14px;"></div>

  <div class="card">
    <h2>Meeting Blocks Missing Room</h2>
    @if($meetingBlocksMissingRoom->count() === 0)
      <p class="muted">None.</p>
    @else
      <table>
        <thead>
          <tr>
            <th>Course</th>
            <th>Section</th>
            <th>Days/Times</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          @foreach($meetingBlocksMissingRoom as $mb)
            <tr>
              <td>{{ $mb->section->offering->catalogCourse->code }}</td>
              <td>{{ $mb->section->section_code }}</td>
              <td class="muted">{{ implode(',', $mb->days_json ?? []) }} {{ substr($mb->starts_at,0,5) }}-{{ substr($mb->ends_at,0,5) }}</td>
              <td><a class="btn secondary" href="{{ route('aop.schedule.sections.edit', $mb->section) }}">Edit Section</a></td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>

  <div style="height:14px;"></div>

  <div class="card">
    <h2>Room Conflicts (Class vs Class)</h2>
    @if(count($roomConflicts) === 0)
      <p class="muted">None.</p>
    @else
      <table>
        <thead>
          <tr>
            <th>Room</th>
            <th>Conflict A</th>
            <th>Conflict B</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          @foreach($roomConflicts as $c)
            <tr>
              <td>{{ $c['room']?->name ?? '—' }}</td>
              <td class="muted">{{ \App\Services\ScheduleConflictService::formatMeetingBlockLabel($c['a']) }}</td>
              <td class="muted">{{ \App\Services\ScheduleConflictService::formatMeetingBlockLabel($c['b']) }}</td>
              <td>
                <a class="btn secondary" href="{{ route('aop.schedule.sections.edit', $c['a']->section_id) }}">Edit A</a>
                <a class="btn secondary" href="{{ route('aop.schedule.sections.edit', $c['b']->section_id) }}">Edit B</a>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>

  <div style="height:14px;"></div>

  <div class="card">
    <h2>Instructor Conflicts</h2>
    @if(count($instructorConflicts) === 0)
      <p class="muted">None.</p>
    @else
      <table>
        <thead>
          <tr>
            <th>Instructor</th>
            <th>Type</th>
            <th>Conflict A</th>
            <th>Conflict B</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          @foreach($instructorConflicts as $c)
            <tr>
              <td>{{ $c['instructor']?->name ?? '—' }}</td>
              <td><span class="badge">{{ $c['type'] }}</span></td>
              <td class="muted">{{ $c['a_label'] }}</td>
              <td class="muted">{{ $c['b_label'] }}</td>
              <td>
                @if($c['a_section_id'])
                  <a class="btn secondary" href="{{ route('aop.schedule.sections.edit', $c['a_section_id']) }}">Edit A</a>
                @endif
                @if($c['b_section_id'])
                  <a class="btn secondary" href="{{ route('aop.schedule.sections.edit', $c['b_section_id']) }}">Edit B</a>
                @endif
                <a class="btn secondary" href="{{ route('aop.schedule.officeHours.show', $c['instructor']?->id ?? 0) }}">Office Hours</a>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>
</x-aop-layout>
