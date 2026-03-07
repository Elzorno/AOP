<x-aop-layout>
  <x-slot:title>Instructors</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>Instructors</h1>
      <p>Manage faculty and adjuncts used in the schedule and syllabi.</p>
    </div>
    <div class="actions">
      <a class="btn" href="{{ route('aop.instructors.create') }}">New Instructor</a>
    </div>
  </div>

  <div class="card">
    <table>
      <thead>
        <tr>
          <th>Name</th>
          <th>Email</th>
          <th>Type</th>
          <th>Status</th>
          <th>Color</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @forelse($instructors as $i)
          @php $safeColor = $i->color_hex_css; @endphp
          <tr>
            <td>{{ $i->name }}</td>
            <td>{{ $i->email ?: '—' }}</td>
            <td>{{ $i->is_full_time ? 'Full-time' : 'Adjunct' }}</td>
            <td>{{ $i->is_active ? 'Active' : 'Inactive' }}</td>
            <td>
              @if ($safeColor)
                <span class="badge" style="background:#f3f4f6; color:#111827; border:1px solid #e5e7eb;">
                  <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:{{ $safeColor }};margin-right:6px;vertical-align:middle;"></span>
                  {{ $safeColor }}
                </span>
              @else
                —
              @endif
            </td>
            <td style="text-align:right;">
              <a class="btn secondary" href="{{ route('aop.instructors.edit', $i) }}">Edit</a>
            </td>
          </tr>
        @empty
          <tr><td colspan="6">No instructors yet.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</x-aop-layout>
