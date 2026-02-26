<x-aop-layout>
  <x-slot:title>Edit Instructor</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <h1>Edit Instructor</h1>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.instructors.index') }}">Back</a>
    </div>
  </div>

  <div class="card">
    <form method="POST" action="{{ route('aop.instructors.update', $instructor) }}">
      @csrf
      @method('PUT')

      <label>Name</label>
      <input name="name" required value="{{ old('name', $instructor->name) }}" />

      <label>Email</label>
      <input name="email" type="email" value="{{ old('email', $instructor->email) }}" />

      <div class="split">
        <div>
          <label>Full-time</label>
          <select name="is_full_time">
            <option value="0" {{ !$instructor->is_full_time ? 'selected' : '' }}>No</option>
            <option value="1" {{ $instructor->is_full_time ? 'selected' : '' }}>Yes</option>
          </select>
        </div>
        <div>
          <label>Color (hex)</label>
          <input name="color_hex" placeholder="#3b82f6" value="{{ old('color_hex', $instructor->color_hex) }}" />
        </div>
        <div>
          <label>Active</label>
          <select name="is_active">
            <option value="1" {{ $instructor->is_active ? 'selected' : '' }}>Yes</option>
            <option value="0" {{ !$instructor->is_active ? 'selected' : '' }}>No</option>
          </select>
        </div>
      </div>

      <div style="height:12px;"></div>
      <button class="btn" type="submit">Save Changes</button>
    </form>
  </div>
</x-aop-layout>
