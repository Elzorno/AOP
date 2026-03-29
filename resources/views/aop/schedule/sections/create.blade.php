<x-aop-layout :activeTermLabel="'Active Term: '.$term->code.' - '.$term->name">
  <x-slot:title>New Section</x-slot:title>

  <div class="page-shell">
    <section class="page-header">
      <span class="page-eyebrow">Fallback Form</span>
      <h1 class="page-title">Create a section for the active term</h1>
      <p class="page-subtitle">
        The schedule studio is still the fastest place to add sections because it keeps the offering context visible, but this focused form is here when you need a dedicated entry screen.
      </p>

      <div class="toolbar-line">
        <a class="btn" href="{{ route('aop.schedule.home') }}">Create in Studio Instead</a>
        <a class="btn secondary" href="{{ route('aop.schedule.sections.index') }}">Section Directory</a>
      </div>
    </section>

    <section class="workspace-card">
      <div class="workspace-header">
        <div>
          <h2 class="workspace-title">Section details</h2>
          <p class="workspace-copy">Choose the offering first, then set the section code, instructor, and modality before moving into meeting times.</p>
        </div>
      </div>

      @if ($offerings->isEmpty())
        <div class="status-note">No offerings exist for this term yet. Create the offering first, then come back to add the section.</div>
        <div class="section-actions">
          <a class="btn" href="{{ route('aop.schedule.home') }}">Go to Studio</a>
          <a class="btn secondary" href="{{ route('aop.schedule.offerings.create') }}">Create Offering</a>
        </div>
      @else
        <form method="POST" action="{{ route('aop.schedule.sections.store') }}">
          @csrf

          <label for="offering_id">Offering</label>
          <select id="offering_id" name="offering_id" required>
            <option value="" disabled {{ old('offering_id') ? '' : 'selected' }}>Choose an offering</option>
            @foreach ($offerings as $offering)
              <option value="{{ $offering->id }}" {{ (string) old('offering_id') === (string) $offering->id ? 'selected' : '' }}>
                {{ $offering->catalogCourse->code }} - {{ $offering->catalogCourse->title }}
              </option>
            @endforeach
          </select>

          <div class="inline-form-grid">
            <div>
              <label for="section_code">Section code</label>
              <input id="section_code" name="section_code" required value="{{ old('section_code') }}" placeholder="01">
            </div>
            <div>
              <label for="instructor_id">Instructor</label>
              <select id="instructor_id" name="instructor_id">
                <option value="">Unassigned</option>
                @foreach ($instructors as $instructor)
                  <option value="{{ $instructor->id }}" {{ (string) old('instructor_id') === (string) $instructor->id ? 'selected' : '' }}>
                    {{ $instructor->name }}
                  </option>
                @endforeach
              </select>
            </div>
            <div>
              <label for="modality">Modality</label>
              <select id="modality" name="modality" required>
                @foreach ($modalities as $modality)
                  <option value="{{ $modality->value }}" {{ old('modality') === $modality->value ? 'selected' : '' }}>
                    {{ str_replace('_', ' ', $modality->value) }}
                  </option>
                @endforeach
              </select>
            </div>
            <div>
              <label for="notes">Notes</label>
              <input id="notes" name="notes" value="{{ old('notes') }}" placeholder="Optional planning notes">
            </div>
          </div>

          <div class="section-actions">
            <button class="btn" type="submit">Create Section</button>
          </div>
        </form>
      @endif
    </section>
  </div>
</x-aop-layout>
