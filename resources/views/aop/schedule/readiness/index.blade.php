<x-aop-layout :activeTermLabel="'Active Term: '.$term->code.' - '.$term->name">
  <x-slot:title>Schedule Readiness</x-slot:title>

  @php
    $roomConflictCount = count($roomConflicts);
    $instructorConflictCount = count($instructorConflicts);
    $blockingIssueCount = $sectionsMissingInstructor->count()
      + $sectionsMissingMeetingBlocks->count()
      + $meetingBlocksMissingRoom->count()
      + $roomConflictCount
      + $instructorConflictCount
      + ($officeHoursFailing ?? collect())->count()
      + ($minutesFailing ?? collect())->count();
  @endphp

  <div class="page-shell">
    <section class="page-header">
      <span class="page-eyebrow">Readiness Review</span>
      <h1 class="page-title">{{ $blockingIssueCount === 0 ? 'The active term is release-ready.' : 'Resolve blockers before publishing '.$term->code.'.' }}</h1>
      <p class="page-subtitle">Instructional minutes, office hours, missing assignments, and conflicts.</p>

      <div class="summary-strip">
        <div class="summary-stat">
          <div class="summary-stat-label">Minutes Failing</div>
          <div class="summary-stat-value">{{ ($minutesFailing ?? collect())->count() }}</div>
          <div class="summary-stat-note">Sections below the required instructional minutes.</div>
        </div>
        <div class="summary-stat">
          <div class="summary-stat-label">Office Hours Failing</div>
          <div class="summary-stat-value">{{ ($officeHoursFailing ?? collect())->count() }}</div>
          <div class="summary-stat-note">Full-time instructors under the office-hours rule.</div>
        </div>
        <div class="summary-stat">
          <div class="summary-stat-label">Missing Assignments</div>
          <div class="summary-stat-value">{{ $sectionsMissingInstructor->count() + $sectionsMissingMeetingBlocks->count() + $meetingBlocksMissingRoom->count() }}</div>
          <div class="summary-stat-note">Instructor, meeting, and room gaps still open.</div>
        </div>
        <div class="summary-stat">
          <div class="summary-stat-label">Conflicts</div>
          <div class="summary-stat-value">{{ $roomConflictCount + $instructorConflictCount }}</div>
          <div class="summary-stat-note">Room and instructor overlap checks currently failing.</div>
        </div>
      </div>

      <div class="toolbar-line">
        <a class="btn" href="{{ route('aop.schedule.home') }}">Back to Schedule</a>
        <a class="btn secondary" href="{{ route('aop.schedule.sections.index') }}">Section Directory</a>
        <a class="btn secondary" href="{{ route('aop.schedule.officeHours.index') }}">Office Hours</a>
        <a class="btn secondary" href="{{ route('aop.schedule.publish.index') }}">Publishing</a>
      </div>
    </section>

    <section class="insight-grid">
      <div class="insight-main">
        <section class="workspace-card">
          <div class="workspace-header">
            <div>
              <div class="briefing-kicker">Instructional minutes</div>
              <h2 class="workspace-title">ODHE / SSU compliance by section</h2>
              <p class="workspace-copy">Required minutes scaled to <strong>{{ $term->weeks_in_term ?? 15 }}</strong> term week{{ ($term->weeks_in_term ?? 15) === 1 ? '' : 's' }}.</p>
            </div>
          </div>

          <div class="table-shell">
            <table>
              <thead>
                <tr>
                  <th>Status</th>
                  <th>Course / Section</th>
                  <th>Lecture / Lab</th>
                  <th>Required</th>
                  <th>Scheduled</th>
                  <th>Delta</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                @forelse($instructionalMinutes as $row)
                  @php
                    $section = $row['section'];
                    $course = $row['course'];
                    $delta = $row['delta_minutes'];
                  @endphp
                  <tr>
                    <td>
                      <span class="badge {{ $row['pass'] ? 'success' : 'danger' }}">{{ $row['pass'] ? 'Pass' : 'Fail' }}</span>
                    </td>
                    <td>
                      <strong>{{ $course?->code ?? '—' }}</strong> - {{ $course?->title ?? 'Untitled' }}<br>
                      <span class="text-slate-500">Section {{ $section->section_code }} · {{ str_replace('_', ' ', $section->modality?->value ?? '—') }}</span>
                    </td>
                    <td>{{ number_format($row['lecture_contact_hours'] ?? 0, 2) }} / {{ number_format($row['lab_contact_hours'] ?? 0, 2) }}</td>
                    <td>{{ number_format($row['required_minutes']) }} min</td>
                    <td>{{ number_format($row['scheduled_minutes']) }} min</td>
                    <td>
                      <span class="badge {{ $delta >= 0 ? 'success' : 'danger' }}">
                        {{ $delta >= 0 ? '+' : '' }}{{ number_format($delta) }} min
                      </span>
                    </td>
                    <td>
                      <a class="btn secondary sm" href="{{ route('aop.schedule.home', ['focus' => $section->id]) }}#section-{{ $section->id }}">Fix in Studio</a>
                    </td>
                  </tr>
                @empty
                  <tr>
                    <td colspan="7">No sections found for the active term.</td>
                  </tr>
                @endforelse
              </tbody>
            </table>
          </div>

          <p class="table-note">
            {{ ($minutesFailing ?? collect())->count() > 0
                ? ($minutesFailing ?? collect())->count().' section(s) currently fail the instructional minutes requirement.'
                : 'All sections currently meet the instructional minutes requirement.' }}
          </p>
        </section>

        <section class="workspace-card">
          <div class="workspace-header">
            <div>
              <div class="briefing-kicker">Office hours</div>
              <h2 class="workspace-title">Full-time instructor compliance</h2>
              <p class="workspace-copy">Full-time instructors need 4 hours across at least 3 days.</p>
            </div>
          </div>

          <div class="table-shell">
            <table>
              <thead>
                <tr>
                  <th>Status</th>
                  <th>Instructor</th>
                  <th>Locked</th>
                  <th>Hours / Week</th>
                  <th>Days</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                @forelse($officeHoursCompliance ?? [] as $row)
                  @php $instructor = $row['instructor']; @endphp
                  <tr>
                    <td>
                      @if(!$row['is_full_time'])
                        <span class="badge muted">N/A</span>
                      @else
                        <span class="badge {{ $row['pass'] ? 'success' : 'danger' }}">{{ $row['pass'] ? 'Pass' : 'Fail' }}</span>
                      @endif
                    </td>
                    <td>
                      <strong>{{ $instructor->name }}</strong><br>
                      <span class="text-slate-500">{{ $instructor->email }}</span>
                    </td>
                    <td>
                      <span class="badge {{ $row['locked'] ? 'success' : 'warn' }}">{{ $row['locked'] ? 'Locked' : 'Open' }}</span>
                    </td>
                    <td>{{ number_format($row['hours_per_week'] ?? 0, 2) }} hrs <span class="text-slate-500">({{ number_format($row['minutes_per_week'] ?? 0) }} min)</span></td>
                    <td>{{ $row['distinct_days'] ?? 0 }}</td>
                    <td>
                      <a class="btn secondary sm" href="{{ route('aop.schedule.officeHours.show', $instructor) }}">Review</a>
                    </td>
                  </tr>
                @empty
                  <tr>
                    <td colspan="6">No instructors found.</td>
                  </tr>
                @endforelse
              </tbody>
            </table>
          </div>

          <p class="table-note">
            {{ ($officeHoursFailing ?? collect())->count() > 0
                ? ($officeHoursFailing ?? collect())->count().' full-time instructor(s) currently fail the office-hours requirement.'
                : 'All full-time instructors currently meet the office-hours requirement.' }}
          </p>
        </section>

        <section class="record-grid">
          <article class="record-card {{ $roomConflictCount > 0 ? 'record-card-danger' : 'record-card-good' }}">
            <div class="briefing-kicker">Room conflicts</div>
            <h2 class="workspace-title mt-2">Physical space overlaps</h2>
            <p class="workspace-copy">Overlapping room assignments.</p>

            @if($roomConflictCount === 0)
              <div class="status-note mt-5">No room conflicts are currently detected.</div>
            @else
              <div class="mini-list">
                @foreach($roomConflicts as $conflict)
                  <div class="mini-list-item">
                    <div>
                      <div class="queue-label">{{ $conflict['room']?->name ?? 'Room' }}</div>
                      <div class="queue-copy">{{ \App\Services\ScheduleConflictService::formatMeetingBlockLabel($conflict['a']) }} vs {{ \App\Services\ScheduleConflictService::formatMeetingBlockLabel($conflict['b']) }}</div>
                    </div>
                    <a class="btn secondary sm" href="{{ route('aop.schedule.home', ['focus' => $conflict['a']->section_id]) }}#section-{{ $conflict['a']->section_id }}">Fix</a>
                  </div>
                @endforeach
              </div>
            @endif
          </article>

          <article class="record-card {{ $instructorConflictCount > 0 ? 'record-card-danger' : 'record-card-good' }}">
            <div class="briefing-kicker">Instructor conflicts</div>
            <h2 class="workspace-title mt-2">Class and office-hour overlaps</h2>
            <p class="workspace-copy">Overlapping class and office-hour assignments.</p>

            @if($instructorConflictCount === 0)
              <div class="status-note mt-5">No instructor conflicts are currently detected.</div>
            @else
              <div class="mini-list">
                @foreach($instructorConflicts as $conflict)
                  <div class="mini-list-item">
                    <div>
                      <div class="queue-label">{{ $conflict['instructor']?->name ?? 'Instructor' }}</div>
                      <div class="queue-copy">{{ $conflict['a_label'] }} vs {{ $conflict['b_label'] }}</div>
                    </div>
                    @if($conflict['a_section_id'])
                      <a class="btn secondary sm" href="{{ route('aop.schedule.home', ['focus' => $conflict['a_section_id']]) }}#section-{{ $conflict['a_section_id'] }}">Fix</a>
                    @endif
                  </div>
                @endforeach
              </div>
            @endif
          </article>
        </section>

        {{-- Distribution metrics (informational only) --}}
        <section class="workspace-card">
          <div class="workspace-header">
            <div>
              <div class="briefing-kicker">Schedule distribution</div>
              <h2 class="workspace-title">Day and time spread</h2>
              <p class="workspace-copy">Informational targets — not a publish gate. Targets based on institutional scheduling rules.</p>
            </div>
          </div>

          @php $d = $distributionStats; @endphp

          <div class="readiness-stat-grid">
            <div class="readiness-stat {{ $d['flags']['friday_low'] ? 'r-warn' : 'r-good' }}">
              <div class="readiness-stat-value">{{ $d['friday_pct'] }}%</div>
              <div class="readiness-stat-label">Sections with a Friday</div>
              <div class="readiness-stat-note">Target: ≥ 12%</div>
            </div>
            <div class="readiness-stat {{ $d['flags']['peak_high'] ? 'r-warn' : 'r-good' }}">
              <div class="readiness-stat-value">{{ $d['peak_hour_pct'] }}%</div>
              <div class="readiness-stat-label">Classroom hours 9:30 am – 3:00 pm</div>
              <div class="readiness-stat-note">Target: ≤ 60%</div>
            </div>
            <div class="readiness-stat {{ $d['flags']['mwf_high'] ? 'r-warn' : 'r-good' }}">
              <div class="readiness-stat-value">{{ $d['mwf_pct'] }}%</div>
              <div class="readiness-stat-label">Classroom hours on M/W/F</div>
              <div class="readiness-stat-note">Target: ≤ 60%</div>
            </div>
            <div class="readiness-stat {{ $d['flags']['tr_high'] ? 'r-warn' : 'r-good' }}">
              <div class="readiness-stat-value">{{ $d['tr_pct'] }}%</div>
              <div class="readiness-stat-label">Classroom hours on T/R</div>
              <div class="readiness-stat-note">Target: ≤ 60%</div>
            </div>
          </div>

          <p class="table-note mt-4">
            Based on {{ number_format($d['totals']['total_minutes']) }} total scheduled classroom minutes across
            {{ $d['totals']['sections'] }} section{{ $d['totals']['sections'] === 1 ? '' : 's' }}.
            These metrics are advisory only and do not affect publishing eligibility.
          </p>
        </section>
      </div>

      <aside class="insight-side">
        <section class="watchlist">
          <div class="briefing-kicker">Action lists</div>
          <h2 class="watchlist-title">Missing instructor</h2>
          <p class="watchlist-copy">Assign these sections first so availability and conflict checks become more accurate.</p>

          @if($sectionsMissingInstructor->isEmpty())
            <div class="status-note mt-5">Every visible section already has an instructor.</div>
          @else
            <div class="mini-list">
              @foreach($sectionsMissingInstructor->take(8) as $section)
                <div class="mini-list-item">
                  <div>
                    <div class="queue-label">{{ $section->offering?->catalogCourse?->code ?? '—' }} {{ $section->section_code }}</div>
                    <div class="queue-copy">{{ $section->offering?->catalogCourse?->title ?? 'Untitled course' }}</div>
                  </div>
                  <a class="btn secondary sm" href="{{ route('aop.schedule.home', ['focus' => $section->id]) }}#section-{{ $section->id }}">Assign</a>
                </div>
              @endforeach
            </div>
          @endif
        </section>

        <section class="watchlist">
          <div class="briefing-kicker">Action lists</div>
          <h2 class="watchlist-title">Missing meeting blocks</h2>
          <p class="watchlist-copy">These sections still need a time pattern before the schedule can be considered complete.</p>

          @if($sectionsMissingMeetingBlocks->isEmpty())
            <div class="status-note mt-5">Every visible section already has at least one meeting block.</div>
          @else
            <div class="mini-list">
              @foreach($sectionsMissingMeetingBlocks->take(8) as $section)
                <div class="mini-list-item">
                  <div>
                    <div class="queue-label">{{ $section->offering?->catalogCourse?->code ?? '—' }} {{ $section->section_code }}</div>
                    <div class="queue-copy">{{ $section->offering?->catalogCourse?->title ?? 'Untitled course' }}</div>
                  </div>
                  <a class="btn secondary sm" href="{{ route('aop.schedule.home', ['focus' => $section->id]) }}#section-{{ $section->id }}">Add Block</a>
                </div>
              @endforeach
            </div>
          @endif
        </section>

        <section class="watchlist">
          <div class="briefing-kicker">Action lists</div>
          <h2 class="watchlist-title">Meeting blocks missing room</h2>
          <p class="watchlist-copy">In-person and hybrid meetings without rooms will block readiness and publication.</p>

          @if($meetingBlocksMissingRoom->isEmpty())
            <div class="status-note mt-5">Every required meeting block already has a room assigned.</div>
          @else
            <div class="mini-list">
              @foreach($meetingBlocksMissingRoom->take(8) as $meetingBlock)
                <div class="mini-list-item">
                  <div>
                    <div class="queue-label">{{ $meetingBlock->section?->offering?->catalogCourse?->code ?? '—' }} {{ $meetingBlock->section?->section_code ?? '' }}</div>
                    <div class="queue-copy">{{ $meetingBlock->type->value }} · {{ implode(', ', $meetingBlock->days_json ?? []) ?: 'No days selected' }}</div>
                  </div>
                  @if($meetingBlock->section)
                    <a class="btn secondary sm" href="{{ route('aop.schedule.home', ['focus' => $meetingBlock->section->id]) }}#section-{{ $meetingBlock->section->id }}">Assign</a>
                  @endif
                </div>
              @endforeach
            </div>
          @endif
        </section>
      </aside>
    </section>
  </div>
</x-aop-layout>
