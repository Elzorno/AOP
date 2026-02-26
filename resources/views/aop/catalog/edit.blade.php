<x-aop-layout>
  <x-slot:title>Edit Catalog Course</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <h1>Edit Catalog Course</h1>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.catalog.index') }}">Back</a>
    </div>
  </div>

  <div class="card">
    <form method="POST" action="{{ route('aop.catalog.update', $course) }}">
      @csrf
      @method('PUT')

      <label>Course Code</label>
      <input name="code" required value="{{ old('code', $course->code) }}" />

      <label>Title</label>
      <input name="title" required value="{{ old('title', $course->title) }}" />

      <div class="split">
        <div>
          <label>Credits</label>
          <input type="number" step="0.01" name="credits" required value="{{ old('credits', $course->credits) }}" />
        </div>
        <div>
          <label>Lecture Hours / Week</label>
          <input type="number" step="0.01" name="lecture_hours_per_week" value="{{ old('lecture_hours_per_week', $course->lecture_hours_per_week) }}" />
        </div>
        <div>
          <label>Lab Hours / Week</label>
          <input type="number" step="0.01" name="lab_hours_per_week" value="{{ old('lab_hours_per_week', $course->lab_hours_per_week) }}" />
        </div>
      </div>

      <label>Description</label>
      <textarea name="description">{{ old('description', $course->description) }}</textarea>

      <div class="split">
        <div>
          <label>Prerequisites</label>
          <textarea name="prereq_text">{{ old('prereq_text', $course->prereq_text) }}</textarea>
        </div>
        <div>
          <label>Corequisites</label>
          <textarea name="coreq_text">{{ old('coreq_text', $course->coreq_text) }}</textarea>
        </div>
      </div>

      <label>Active</label>
      <select name="is_active">
        <option value="1" {{ $course->is_active ? 'selected' : '' }}>Yes</option>
        <option value="0" {{ !$course->is_active ? 'selected' : '' }}>No</option>
      </select>

      <div style="height:12px;"></div>
      <button class="btn" type="submit">Save Changes</button>
    </form>
  </div>
</x-aop-layout>
