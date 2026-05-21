<x-aop-layout :activeTermLabel="'Active Term: '.$term->code.' - '.$term->name">
  <x-slot:title>Sections</x-slot:title>

  @php
    $sectionsMissingInstructorCount = $sections->whereNull('instructor_id')->count();
    $sectionsMissingMeetingsCount = $sections->filter(fn ($section) => $section->meetingBlocks->isEmpty())->count();
    $sectionsMissingRoomsCount = $sections->filter(function ($section) {
        $roomRequired = ($section->modality?->value ?? null) !== 'ONLINE';

        return $roomRequired && $section->meetingBlocks->contains(fn ($meetingBlock) => !$meetingBlock->room_id);
    })->count();
  @endphp

  <div class="page-shell">
    <section class="page-header">
      <span class="page-eyebrow">Section Directory</span>
      <h1 class="page-title">Every section in the active term, with its missing pieces visible.</h1>
      <p class="page-subtitle">Filter sections, spot gaps, and open the next one to fix.</p>

      <div class="summary-strip">
        <div class="summary-stat">
          <div class="summary-stat-label">Visible Sections</div>
          <div class="summary-stat-value">{{ $sections->count() }}</div>
          <div class="summary-stat-note">Current result set after filters.</div>
        </div>
        <div class="summary-stat">
          <div class="summary-stat-label">Missing Instructor</div>
          <div class="summary-stat-value">{{ $sectionsMissingInstructorCount }}</div>
          <div class="summary-stat-note">Sections that still need an instructor assignment.</div>
        </div>
        <div class="summary-stat">
          <div class="summary-stat-label">Missing Meetings</div>
          <div class="summary-stat-value">{{ $sectionsMissingMeetingsCount }}</div>
          <div class="summary-stat-note">Sections that are not schedulable yet.</div>
        </div>
        <div class="summary-stat">
          <div class="summary-stat-label">Missing Rooms</div>
          <div class="summary-stat-value">{{ $sectionsMissingRoomsCount }}</div>
          <div class="summary-stat-note">In-person or hybrid blocks still missing rooms.</div>
        </div>
      </div>

      <div class="toolbar-line">
        <a class="btn" href="{{ route('aop.schedule.home') }}">Open Schedule Studio</a>
        <a class="btn secondary" href="{{ route('aop.schedule.readiness.index') }}">Readiness</a>
        <a class="btn secondary" href="{{ route('aop.schedule.sections.create') }}">Fallback Create Form</a>
      </div>
    </section>

    <section class="workspace-card">
      <div class="workspace-header">
        <div>
          <h2 class="workspace-title">Filter the section queue</h2>
          <p class="workspace-copy">Search by course, section, or instructor.</p>
        </div>
      </div>

      <form method="GET" action="{{ route('aop.schedule.sections.index') }}">
        <div class="filter-grid">
          <div class="md:col-span-2 xl:col-span-2">
            <label for="q">Search</label>
            <input id="q" type="text" name="q" value="{{ $filters['q'] }}" placeholder="Course code, title, section, instructor">
          </div>
          <div>
            <label for="modality">Modality</label>
            <select id="modality" name="modality">
              <option value="">All modalities</option>
              @foreach ($modalities as $m)
                <option value="{{ $m->value }}" {{ $filters['modality'] === $m->value ? 'selected' : '' }}>{{ str_replace('_', ' ', $m->value) }}</option>
              @endforeach
            </select>
          </div>
          <div>
            <label for="instructor_id">Instructor</label>
            <select id="instructor_id" name="instructor_id">
              <option value="">All instructors</option>
              @foreach ($instructors as $i)
                <option value="{{ $i->id }}" {{ (int) $filters['instructor_id'] === $i->id ? 'selected' : '' }}>{{ $i->name }}</option>
              @endforeach
            </select>
          </div>
          <div>
            <label for="missing">Needs</label>
            <select id="missing" name="missing">
              <option value="">Any state</option>
              <option value="instructor" {{ $filters['missing'] === 'instructor' ? 'selected' : '' }}>Missing instructor</option>
              <option value="meeting_blocks" {{ $filters['missing'] === 'meeting_blocks' ? 'selected' : '' }}>Missing meeting blocks</option>
              <option value="room" {{ $filters['missing'] === 'room' ? 'selected' : '' }}>Missing room</option>
              <option value="low_enrollment" {{ $filters['missing'] === 'low_enrollment' ? 'selected' : '' }}>Low enrollment (≤70%)</option>
            </select>
          </div>
          <div>
            <label for="sort">Sort</label>
            <select id="sort" name="sort">
              <option value="recent" {{ $filters['sort'] === 'recent' ? 'selected' : '' }}>Recently changed</option>
              <option value="course" {{ $filters['sort'] === 'course' ? 'selected' : '' }}>Course</option>
              <option value="section" {{ $filters['sort'] === 'section' ? 'selected' : '' }}>Section</option>
              <option value="instructor" {{ $filters['sort'] === 'instructor' ? 'selected' : '' }}>Instructor</option>
            </select>
          </div>
          <div class="actions md:items-end">
            <button type="submit" class="btn secondary">Apply</button>
            <a class="btn secondary" href="{{ route('aop.schedule.sections.index') }}">Reset</a>
          </div>
        </div>
      </form>
    </section>

    @if($sections->isEmpty())
      <section class="workspace-card">
        <div class="workspace-header">
          <div>
            <h2 class="workspace-title">No sections match the current filters</h2>
            <p class="workspace-copy">Reset the filters or add another section.</p>
          </div>
          <div class="actions">
            <a class="btn" href="{{ route('aop.schedule.home') }}">Go to Studio</a>
          </div>
        </div>
      </section>
    @else
      {{-- Bulk assign form wraps the entire grid --}}
      <form id="bulk-form" method="POST" action="{{ route('aop.schedule.sections.bulkAssign') }}">
        @csrf

        {{-- Bulk action bar (shown when selections exist) --}}
        <div id="bulk-bar" class="bulk-bar" style="display:none;" aria-live="polite">
          <span class="bulk-bar-count" id="bulk-bar-count">0 selected</span>
          <select name="instructor_id" id="bulk-instructor" class="bulk-bar-select">
            <option value="">Unassign instructor</option>
            @foreach ($instructors as $i)
              <option value="{{ $i->id }}">{{ $i->name }}</option>
            @endforeach
          </select>
          <button type="submit" class="btn">Assign to selected</button>
          <button type="button" class="btn secondary" onclick="clearBulkSelection()">Clear</button>
        </div>

        <section class="directory-grid">
          @foreach($sections as $section)
            @php
              $course = $section->offering->catalogCourse;
              $roomRequired = ($section->modality?->value ?? null) !== 'ONLINE';
              $missingInstructor = !$section->instructor_id;
              $missingMeetings = $section->meetingBlocks->isEmpty();
              $missingRoom = $roomRequired && $section->meetingBlocks->contains(fn ($meetingBlock) => !$meetingBlock->room_id);
              $lowEnrollment = $section->section_capacity && $section->enrolled_count <= floor($section->section_capacity * 0.70);
              $enrollPct = ($section->section_capacity > 0) ? round(($section->enrolled_count / $section->section_capacity) * 100) : null;
              $issueCount = ($missingInstructor ? 1 : 0) + ($missingMeetings ? 1 : 0) + ($missingRoom ? 1 : 0) + ($lowEnrollment ? 1 : 0);
            @endphp
            <article
              class="directory-card {{ $issueCount > 0 ? 'directory-card-danger' : 'directory-card-good' }} bulk-card"
              data-section-id="{{ $section->id }}"
            >
              {{-- Checkbox in top-left corner --}}
              <label class="bulk-checkbox-wrap" title="Select for bulk action">
                <input
                  type="checkbox"
                  name="section_ids[]"
                  value="{{ $section->id }}"
                  class="bulk-checkbox"
                  onchange="updateBulkBar()"
                  aria-label="Select {{ $course->code }} {{ $section->section_code }}"
                />
              </label>

              <div class="offering-head">
                <div>
                  <div class="offering-kicker">Section</div>
                  <h2 class="offering-title">{{ $course->code }} {{ $section->section_code }}</h2>
                  <p class="offering-copy">{{ $course->title }}</p>
                </div>
                <div class="section-badges">
                  <span class="badge info">{{ $section->meetingBlocks->count() }} meeting block{{ $section->meetingBlocks->count() === 1 ? '' : 's' }}</span>
                  @if($issueCount === 0)
                    <span class="badge success">Ready</span>
                  @endif
                  @if($missingInstructor)
                    <span class="badge warn">Instructor needed</span>
                  @endif
                  @if($missingMeetings)
                    <span class="badge warn">Meetings missing</span>
                  @endif
                  @if($missingRoom)
                    <span class="badge warn">Room needed</span>
                  @endif
                  @if($lowEnrollment)
                    <span class="badge warn">Low enrollment {{ $enrollPct }}%</span>
                  @endif
                </div>
              </div>

              <div class="directory-meta">
                <div class="offering-meta-card">
                  <strong class="block text-slate-900">Instructor</strong>
                  <span>{{ $section->instructor?->name ?? 'Unassigned' }}</span>
                </div>
                <div class="offering-meta-card">
                  <strong class="block text-slate-900">Modality</strong>
                  <span>{{ str_replace('_', ' ', $section->modality?->value ?? 'Unspecified') }}</span>
                </div>
                <div class="offering-meta-card">
                  <strong class="block text-slate-900">Enrollment</strong>
                  <span>
                    @if ($section->section_capacity)
                      {{ $section->enrolled_count }} / {{ $section->section_capacity }}
                      @if ($lowEnrollment)
                        <span style="color:var(--amber-600,#d97706);"> ({{ $enrollPct }}%)</span>
                      @endif
                    @else
                      Not tracked
                    @endif
                  </span>
                </div>
                <div class="offering-meta-card">
                  <strong class="block text-slate-900">Meetings</strong>
                  <span>
                    @if($section->meetingBlocks->isEmpty())
                      None scheduled yet
                    @else
                      {{ $section->meetingBlocks->map(fn ($meetingBlock) => implode('/', $meetingBlock->days_json ?? []).' '.substr($meetingBlock->starts_at, 0, 5))->implode(', ') }}
                    @endif
                  </span>
                </div>
              </div>

              @if($section->notes)
                <div class="status-note mt-4">{{ $section->notes }}</div>
              @endif

              <div class="section-actions">
                <a class="btn" href="{{ route('aop.schedule.home', ['focus' => $section->id]) }}#section-{{ $section->id }}">Open</a>
                <a class="btn secondary" href="{{ route('aop.schedule.sections.edit', $section) }}">Edit</a>
              </div>
            </article>
          @endforeach
        </section>
      </form>

      <script>
        function updateBulkBar() {
          const checked = document.querySelectorAll('.bulk-checkbox:checked');
          const bar = document.getElementById('bulk-bar');
          const count = document.getElementById('bulk-bar-count');
          bar.style.display = checked.length > 0 ? 'flex' : 'none';
          count.textContent = checked.length + ' selected';
        }
        function clearBulkSelection() {
          document.querySelectorAll('.bulk-checkbox').forEach(cb => cb.checked = false);
          updateBulkBar();
        }
      </script>
    @endif
  </div>
</x-aop-layout>
