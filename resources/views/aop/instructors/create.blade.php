<x-aop-layout>
  <x-slot:title>New Instructor</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <h1>New Instructor</h1>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.instructors.index') }}">Back</a>
    </div>
  </div>

  <div class="card">
    <form method="POST" action="{{ route('aop.instructors.store') }}">
      @csrf
      <label>Name</label>
      <input name="name" required value="{{ old('name') }}" />

      <label>Email</label>
      <input name="email" type="email" value="{{ old('email') }}" />

      <div class="split">
        <div>
          <label>Full-time</label>
          <select name="is_full_time">
            <option value="0" selected>No</option>
            <option value="1">Yes</option>
          </select>
        </div>
        <div>
          <label>Color (hex)</label>
          <input name="color_hex" placeholder="#3b82f6" value="{{ old('color_hex') }}" />
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
      <button class="btn" type="submit">Create Instructor</button>
    </form>
  </div>
</x-aop-layout>
