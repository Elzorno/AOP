<x-aop-layout :activeTermLabel="'Active Term: '.$term->code.' — '.$term->name">
  <x-slot:title>Offerings</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <h1>Offerings</h1>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.schedule.home') }}">Back</a>
      <a class="btn" href="{{ route('aop.schedule.offerings.create') }}">New Offering</a>
    </div>
  </div>

  <div class="card">
    <table>
      <thead>
        <tr>
          <th>Course</th>
          <th>Delivery</th>
          <th>Notes</th>
          <th>Prereq / Coreq</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($offerings as $o)
          <tr>
            <td>{{ $o->catalogCourse->code }} — {{ $o->catalogCourse->title }}</td>
            <td>{{ $o->delivery_method ?? '—' }}</td>
            <td>{{ $o->notes ? \Illuminate\Support\Str::limit($o->notes, 80) : '—' }}</td>
            <td>
              <div><strong>Prereq:</strong> {{ $o->prereq_override ?? '—' }}</div>
              <div><strong>Coreq:</strong> {{ $o->coreq_override ?? '—' }}</div>
            </td>
          </tr>
        @empty
          <tr><td colspan="4">No offerings yet.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</x-aop-layout>
