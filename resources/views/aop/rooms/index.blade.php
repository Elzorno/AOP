<x-aop-layout>
  <x-slot:title>Rooms</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <h1>Rooms</h1>
    <div class="actions">
      <a class="btn" href="{{ route('aop.rooms.create') }}">New Room</a>
    </div>
  </div>

  <div class="card">
    <table>
      <thead>
        <tr>
          <th>Name</th>
          <th>Building</th>
          <th>Room #</th>
          <th>Active</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @foreach ($rooms as $r)
          <tr>
            <td>{{ $r->name }}</td>
            <td>{{ $r->building ?? '—' }}</td>
            <td>{{ $r->room_number ?? '—' }}</td>
            <td>{{ $r->is_active ? 'Yes' : 'No' }}</td>
            <td><a class="btn link" href="{{ route('aop.rooms.edit', $r) }}">Edit</a></td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
</x-aop-layout>
