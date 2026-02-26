<x-aop-layout :activeTermLabel="'Active Term: '.$term->code.' — '.$term->name">
  <x-slot:title>Sections</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <h1>Sections</h1>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.schedule.home') }}">Back</a>
      <a class="btn" href="{{ route('aop.schedule.sections.create') }}">New Section</a>
    </div>
  </div>

  <div class="card">
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
