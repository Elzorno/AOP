<x-aop-layout :activeTermLabel="'Active Term: '.$term->code.' — '.$term->name">
  <x-slot:title>Sections</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <h1>Sections</h1>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.schedule.home') }}">Back</a>
      <a class="btn" href="{{ route('aop.schedule.sections.create') }}">New Section</a>
    </div>
  </div>

  <div class="card" style="margin-bottom:14px;">
    <form method="GET" action="{{ route('aop.schedule.sections.index') }}">
      <div class="split" style="gap:12px; align-items:end;">
        <div>
          <label>Search</label>
          <input type="text" name="q" value="{{ $filters['q'] }}" placeholder="Course code/title, section, instructor" />
        </div>
        <div>
          <label>Modality</label>
          <select name="modality">
            <option value="">All</option>
            @foreach ($modalities as $m)
              <option value="{{ $m->value }}" {{ $filters['modality'] === $m->value ? 'selected' : '' }}>{{ $m->value }}</option>
            @endforeach
          </select>
        </div>
        <div>
          <label>Instructor</label>
          <select name="instructor_id">
            <option value="">All</option>
            @foreach ($instructors as $i)
              <option value="{{ $i->id }}" {{ (int) $filters['instructor_id'] === $i->id ? 'selected' : '' }}>{{ $i->name }}</option>
            @endforeach
          </select>
        </div>
        <div>
          <label>Missing</label>
          <select name="missing">
            <option value="">Any</option>
            <option value="instructor" {{ $filters['missing'] === 'instructor' ? 'selected' : '' }}>Missing Instructor</option>
            <option value="meeting_blocks" {{ $filters['missing'] === 'meeting_blocks' ? 'selected' : '' }}>Missing Meeting Blocks</option>
            <option value="room" {{ $filters['missing'] === 'room' ? 'selected' : '' }}>Missing Room</option>
          </select>
        </div>
        <div>
          <label>Sort</label>
          <select name="sort">
            <option value="recent" {{ $filters['sort'] === 'recent' ? 'selected' : '' }}>Recent</option>
            <option value="course" {{ $filters['sort'] === 'course' ? 'selected' : '' }}>Course</option>
            <option value="section" {{ $filters['sort'] === 'section' ? 'selected' : '' }}>Section</option>
            <option value="instructor" {{ $filters['sort'] === 'instructor' ? 'selected' : '' }}>Instructor</option>
          </select>
        </div>
        <div class="actions">
          <button type="submit" class="btn">Apply</button>
          <a class="btn secondary" href="{{ route('aop.schedule.sections.index') }}">Reset</a>
        </div>
      </div>
    </form>
  </div>

  <div class="card">
    <p class="muted" style="margin:0 0 10px 0;">Showing {{ $sections->count() }} section(s).</p>
    <table>
      <thead>
        <tr>
          <th>Course</th>
          <th>Section</th>
          <th>Instructor</th>
          <th>Modality</th>
          <th>Meeting Blocks</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @forelse ($sections as $s)
          <tr>
            <td>{{ $s->offering->catalogCourse->code }} — {{ $s->offering->catalogCourse->title }}</td>
            <td>{{ $s->section_code }}</td>
            <td>{{ $s->instructor?->name ?? '—' }}</td>
            <td>{{ $s->modality->value }}</td>
            <td>{{ $s->meetingBlocks->count() }}</td>
            <td><a class="btn link" href="{{ route('aop.schedule.sections.edit', $s) }}">Edit</a></td>
          </tr>
        @empty
          <tr><td colspan="6">No sections yet.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</x-aop-layout>
