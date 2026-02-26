<x-aop-layout>
  <x-slot:title>Edit Catalog Course</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <h1>Edit Catalog Course</h1>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.catalog.index') }}">Back</a>
    </div>
  </div>

  <div class="card">
    @php($courseId = data_get($course, "id"))
    <form method="POST" action="{{ route('aop.catalog.update', ['course' => $courseId]) }}">
      @csrf
      @method('PUT')

      <label>Course Code</label>
      <input name="code" required value="{{ old('code', $course->code) }}" />

      <label>Title</label>
      <input name="title" required value="{{ old('title', $course->title) }}" />

      <label>Department (optional)</label>
      <input name="department" value="{{ old('department', $course->department) }}" placeholder="Department of Engineering Technologies – Information Security/Cyber Security" />

      <div class="split">
        <div>
          <label>Credits</label>
          <input type="number" step="0.01" name="credits" required value="{{ old('credits', $course->credits) }}" />
        </div>
        <div>
          <label>Credits Text (optional)</label>
          <input name="credits_text" value="{{ old('credits_text', $course->credits_text) }}" placeholder="3" />
        </div>
        <div>
          <label>Credits Min (optional)</label>
          <input type="number" step="0.01" name="credits_min" value="{{ old('credits_min', $course->credits_min) }}" />
        </div>
        <div>
          <label>Credits Max (optional)</label>
          <input type="number" step="0.01" name="credits_max" value="{{ old('credits_max', $course->credits_max) }}" />
        </div>
      </div>

      <div class="split">
        <div>
          <label>Lecture Hours / Week</label>
          <input type="number" step="0.01" name="lecture_hours_per_week" value="{{ old('lecture_hours_per_week', $course->lecture_hours_per_week) }}" />
        </div>
        <div>
          <label>Lab Hours / Week</label>
          <input type="number" step="0.01" name="lab_hours_per_week" value="{{ old('lab_hours_per_week', $course->lab_hours_per_week) }}" />
        </div>
        <div>
          <label>Contact Hours / Week (same for all sections)</label>
          <input type="number" step="0.01" name="contact_hours_per_week" value="{{ old('contact_hours_per_week', $course->contact_hours_per_week) }}" />
        </div>
        <div>
          <label>Course Lab Fee (optional)</label>
          <input name="course_lab_fee" value="{{ old('course_lab_fee', $course->course_lab_fee) }}" placeholder="$0" />
        </div>
      </div>

      <label>Description</label>
      <textarea name="description" rows="5">{{ old('description', $course->description) }}</textarea>

      <label>Course Objectives</label>
      <textarea name="objectives" rows="6">{{ old('objectives', $course->objectives) }}</textarea>

      <label>Required Materials</label>
      <textarea name="required_materials" rows="4">{{ old('required_materials', $course->required_materials) }}</textarea>

      <div class="split">
        <div>
          <label>Prerequisites</label>
          <textarea name="prereq_text" rows="4">{{ old('prereq_text', $course->prereq_text) }}</textarea>
        </div>
        <div>
          <label>Corequisites</label>
          <textarea name="coreq_text" rows="4">{{ old('coreq_text', $course->coreq_text) }}</textarea>
        </div>
      </div>

      <label>Notes (optional)</label>
      <textarea name="notes" rows="4">{{ old('notes', $course->notes) }}</textarea>

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
