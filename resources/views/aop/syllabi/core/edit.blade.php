@php
  $catalogCourse = $section->offering->catalogCourse ?? null;
  $coreContent = $packet['core_content'] ?? [];
@endphp

<x-aop-layout :activeTermLabel="$term ? 'Active Term: '.$term->code.' — '.$term->name : 'No active term selected'">
  <x-slot:title>Edit Core Syllabus Content</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>Edit Core Syllabus Content</h1>
      <p class="muted" style="margin-top:6px; max-width:940px;">
        {{ $packet['course']['code'] ?? '' }} — Section {{ $packet['section']['code'] ?? '' }}.
        Use this page to override the fixed top syllabus content for this one section only. Leave a field blank to use the catalog default.
      </p>
    </div>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.syllabi.show', $section) }}">Back to Preview</a>
      @if($catalogCourse)
        <a class="btn secondary" href="{{ route('aop.catalog.edit', $catalogCourse) }}">Edit Catalog Course</a>
      @endif
    </div>
  </div>

  <div class="card" style="margin-bottom:14px;">
    <h2>How this works</h2>
    <p class="muted" style="margin-top:6px; max-width:960px;">
      The fixed top content in the syllabus can come from either the shared catalog course or a per-syllabus override for this section.
      Saving non-blank text here creates an override. Clearing a field and saving sends it back to the catalog default.
    </p>
  </div>

  <form method="POST" action="{{ route('aop.syllabi.core.update', $section) }}">
    @csrf
    @method('PUT')

    <div class="card" style="margin-bottom:14px;">
      <h2>Course Description</h2>
      <div class="actions" style="margin-top:8px; gap:8px;">
        @if(($coreContent['course_description']['source'] ?? '') === 'override')
          <span class="badge warn">Per-Syllabus Override</span>
        @elseif(($coreContent['course_description']['source'] ?? '') === 'catalog')
          <span class="badge info">Catalog Default</span>
        @else
          <span class="badge danger">Missing</span>
        @endif
      </div>
      <label>Override for this syllabus</label>
      <textarea name="course_description_override" rows="10" placeholder="Leave blank to use the catalog course description.">{{ old('course_description_override', $syllabus->course_description_override) }}</textarea>
      <div class="muted" style="margin-top:8px; font-size:12px;">Blank = use catalog default for this syllabus.</div>
      <div class="split" style="margin-top:12px;">
        <div>
          <h3 style="margin-bottom:6px;">Current Effective Value</h3>
          <div class="card" style="padding:12px; border-radius:12px; background:#fafafa; white-space:pre-wrap;">{{ ($coreContent['course_description']['value'] ?? '') !== '' ? $coreContent['course_description']['value'] : 'No content entered yet.' }}</div>
        </div>
        <div>
          <h3 style="margin-bottom:6px;">Catalog Default</h3>
          <div class="card" style="padding:12px; border-radius:12px; background:#fafafa; white-space:pre-wrap;">{{ ($coreContent['course_description']['catalog_value'] ?? '') !== '' ? $coreContent['course_description']['catalog_value'] : 'No catalog content entered yet.' }}</div>
        </div>
      </div>
    </div>

    <div class="card" style="margin-bottom:14px;">
      <h2>Course Objectives</h2>
      <div class="actions" style="margin-top:8px; gap:8px;">
        @if(($coreContent['course_objectives']['source'] ?? '') === 'override')
          <span class="badge warn">Per-Syllabus Override</span>
        @elseif(($coreContent['course_objectives']['source'] ?? '') === 'catalog')
          <span class="badge info">Catalog Default</span>
        @else
          <span class="badge danger">Missing</span>
        @endif
      </div>
      <label>Override for this syllabus</label>
      <textarea name="course_objectives_override" rows="10" placeholder="Leave blank to use the catalog course objectives.">{{ old('course_objectives_override', $syllabus->course_objectives_override) }}</textarea>
      <div class="muted" style="margin-top:8px; font-size:12px;">Blank = use catalog default for this syllabus.</div>
      <div class="split" style="margin-top:12px;">
        <div>
          <h3 style="margin-bottom:6px;">Current Effective Value</h3>
          <div class="card" style="padding:12px; border-radius:12px; background:#fafafa; white-space:pre-wrap;">{{ ($coreContent['course_objectives']['value'] ?? '') !== '' ? $coreContent['course_objectives']['value'] : 'No content entered yet.' }}</div>
        </div>
        <div>
          <h3 style="margin-bottom:6px;">Catalog Default</h3>
          <div class="card" style="padding:12px; border-radius:12px; background:#fafafa; white-space:pre-wrap;">{{ ($coreContent['course_objectives']['catalog_value'] ?? '') !== '' ? $coreContent['course_objectives']['catalog_value'] : 'No catalog content entered yet.' }}</div>
        </div>
      </div>
    </div>

    <div class="card" style="margin-bottom:14px;">
      <h2>Required Materials</h2>
      <div class="actions" style="margin-top:8px; gap:8px;">
        @if(($coreContent['required_materials']['source'] ?? '') === 'override')
          <span class="badge warn">Per-Syllabus Override</span>
        @elseif(($coreContent['required_materials']['source'] ?? '') === 'catalog')
          <span class="badge info">Catalog Default</span>
        @else
          <span class="badge danger">Missing</span>
        @endif
      </div>
      <label>Override for this syllabus</label>
      <textarea name="required_materials_override" rows="10" placeholder="Leave blank to use the catalog required materials.">{{ old('required_materials_override', $syllabus->required_materials_override) }}</textarea>
      <div class="muted" style="margin-top:8px; font-size:12px;">Blank = use catalog default for this syllabus.</div>
      <div class="split" style="margin-top:12px;">
        <div>
          <h3 style="margin-bottom:6px;">Current Effective Value</h3>
          <div class="card" style="padding:12px; border-radius:12px; background:#fafafa; white-space:pre-wrap;">{{ ($coreContent['required_materials']['value'] ?? '') !== '' ? $coreContent['required_materials']['value'] : 'No content entered yet.' }}</div>
        </div>
        <div>
          <h3 style="margin-bottom:6px;">Catalog Default</h3>
          <div class="card" style="padding:12px; border-radius:12px; background:#fafafa; white-space:pre-wrap;">{{ ($coreContent['required_materials']['catalog_value'] ?? '') !== '' ? $coreContent['required_materials']['catalog_value'] : 'No catalog content entered yet.' }}</div>
        </div>
      </div>
    </div>

    <div class="actions">
      <button class="btn" type="submit">Save Core Content</button>
      <a class="btn secondary" href="{{ route('aop.syllabi.show', $section) }}">Cancel</a>
    </div>
  </form>
</x-aop-layout>
