<x-aop-layout :activeTermLabel="'Working term: '.$term->code.' - '.$term->name">
  <x-slot:title>New Offering</x-slot:title>

  <div class="page-shell">
    <section class="page-header">
      <span class="page-eyebrow">Offerings</span>
      <h1 class="page-title">Create an offering for {{ $term->code }}</h1>
      <p class="page-subtitle">Add a catalog course to this explicitly selected term.</p>

      <div class="toolbar-line">
        @if($term->is_active)
          <a class="btn" href="{{ route('aop.schedule.home', $term) }}">Back to Schedule</a>
        @else
          <a class="btn" href="{{ route('aop.terms.index') }}">Back to Terms</a>
        @endif
        <a class="btn secondary" href="{{ route('aop.schedule.offerings.index', $term) }}">Offering Overview</a>
      </div>
    </section>

    <section class="workspace-card">
      <div class="workspace-header">
        <div>
          <h2 class="workspace-title">Offering details</h2>
          <p class="workspace-copy">Course, delivery method, and term-specific overrides.</p>
        </div>
      </div>

      <form method="POST" action="{{ route('aop.schedule.offerings.store', $term) }}">
        @csrf

        <label for="catalog_course_id">Catalog course</label>
        <select id="catalog_course_id" name="catalog_course_id" required>
          <option value="" disabled {{ old('catalog_course_id') ? '' : 'selected' }}>Choose a course</option>
          @foreach ($courses as $course)
            <option value="{{ $course->id }}" {{ (string) old('catalog_course_id') === (string) $course->id ? 'selected' : '' }}>
              {{ $course->code }} - {{ $course->title }}
            </option>
          @endforeach
        </select>

        <div class="inline-form-grid-2">
          <div>
            <label for="delivery_method">Delivery method</label>
            <input id="delivery_method" name="delivery_method" value="{{ old('delivery_method') }}" placeholder="Lecture, lab, hybrid">
          </div>
          <div>
            <label for="notes">Notes</label>
            <input id="notes" name="notes" value="{{ old('notes') }}" placeholder="Optional planning notes">
          </div>
        </div>

        <div class="inline-form-grid-2">
          <div>
            <label for="prereq_override">Prerequisite override</label>
            <textarea id="prereq_override" name="prereq_override" rows="4">{{ old('prereq_override') }}</textarea>
          </div>
          <div>
            <label for="coreq_override">Corequisite override</label>
            <textarea id="coreq_override" name="coreq_override" rows="4">{{ old('coreq_override') }}</textarea>
          </div>
        </div>

        <div class="section-actions">
          <button class="btn" type="submit">Create Offering</button>
        </div>
      </form>
    </section>
  </div>
</x-aop-layout>
