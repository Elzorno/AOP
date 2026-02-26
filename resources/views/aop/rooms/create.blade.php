<x-aop-layout>
  <x-slot:title>New Room</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <h1>New Room</h1>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.rooms.index') }}">Back</a>
    </div>
  </div>

  <div class="card">
    <form method="POST" action="{{ route('aop.rooms.store') }}">
      @csrf
      <label>Name</label>
      <input name="name" required value="{{ old('name') }}" placeholder="ATC 228" />

      <div class="split">
        <div>
          <label>Building</label>
          <input name="building" value="{{ old('building') }}" />
        </div>
        <div>
          <label>Room Number</label>
          <input name="room_number" value="{{ old('room_number') }}" />
        </div>
        <div>
          <label>Active</label>
          <select name="is_active">
            <option value="1" selected>Yes</option>
            <option value="0">No</option>
          </select>
        </div>
      </div>

      <div style="height:12px;"></div>
      <button class="btn" type="submit">Create Room</button>
    </form>
  </div>
</x-aop-layout>
