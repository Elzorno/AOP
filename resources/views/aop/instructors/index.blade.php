<x-aop-layout>
  <x-slot:title>Instructors</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <h1>Instructors</h1>
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
          <th>Full-time</th>
          <th>Color</th>
          <th>Active</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @foreach ($instructors as $i)
          <tr>
            <td>{{ $i->name }}</td>
            <td>{{ $i->email ?? '—' }}</td>
            <td>{{ $i->is_full_time ? 'Yes' : 'No' }}</td>
            <td>
              @if ($i->color_hex)
                <span class="badge" style="background:#f3f4f6; color:#111827; border:1px solid #e5e7eb;">
                  <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:{{ $i->color_hex }};margin-right:6px;vertical-align:middle;"></span>
                  {{ $i->color_hex }}
                </span>
              @else
                —
              @endif
            </td>
            <td>{{ $i->is_active ? 'Yes' : 'No' }}</td>
            <td><a class="btn link" href="{{ route('aop.instructors.edit', $i) }}">Edit</a></td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
</x-aop-layout>
