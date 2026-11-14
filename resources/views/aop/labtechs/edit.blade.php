<x-aop-layout>
  <x-slot:title>Edit Lab Tech</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <h1>Edit Lab Tech</h1>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.labtechs.index') }}">Back</a>
    </div>
  </div>

  <div class="card">
    <form method="POST" action="{{ route('aop.labtechs.update', $labTech) }}">
      @csrf
      @method('PUT')

      <label>Name</label>
      <input name="name" required value="{{ old('name', $labTech->name) }}" />

      <label>Email</label>
      <input name="email" type="email" value="{{ old('email', $labTech->email) }}" />

      <div class="split">
        <div>
          <label>Weekly HR Limit (hours)</label>
          <input type="number" name="weekly_hour_limit" min="0" max="168" step="0.25" required value="{{ old('weekly_hour_limit', $labTech->weekly_hour_limit) }}" />
        </div>
        <div>
          <label>Active</label>
          <select name="is_active">
            <option value="1" {{ $labTech->is_active ? 'selected' : '' }}>Yes</option>
            <option value="0" {{ !$labTech->is_active ? 'selected' : '' }}>No</option>
          </select>
        </div>
      </div>

      <div style="height:12px;"></div>
      <button class="btn" type="submit">Save Changes</button>
    </form>
  </div>
</x-aop-layout>
