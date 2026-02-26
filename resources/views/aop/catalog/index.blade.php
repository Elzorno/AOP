<x-aop-layout>
  <x-slot:title>Catalog</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <h1>Catalog Courses</h1>
    <div class="actions">
      <a class="btn" href="{{ route('aop.catalog.create') }}">New Course</a>
    </div>
  </div>

  <div class="card">
    <table>
      <thead>
        <tr>
          <th>Code</th>
          <th>Title</th>
          <th>Credits</th>
          <th>Lec Hrs</th>
          <th>Lab Hrs</th>
          <th>Active</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @foreach ($courses as $c)
          <tr>
            <td>{{ $c->code }}</td>
            <td>{{ $c->title }}</td>
            <td>{{ $c->credits }}</td>
            <td>{{ $c->lecture_hours_per_week ?? '—' }}</td>
            <td>{{ $c->lab_hours_per_week ?? '—' }}</td>
            <td>{{ $c->is_active ? 'Yes' : 'No' }}</td>
            <td><a class="btn link" href="{{ route('aop.catalog.edit', $c) }}">Edit</a></td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
</x-aop-layout>
