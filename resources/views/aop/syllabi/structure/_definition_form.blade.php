@php
  $initialMarkdown = old('default_content', data_get($definition, 'default_content'));
@endphp

<link rel="stylesheet" href="https://uicdn.toast.com/editor/latest/toastui-editor.min.css">

@if($errors->any())
  <div class="mb-6 rounded-2xl border border-red-200 bg-red-50/95 p-4 shadow-sm">
    <strong class="text-sm font-semibold text-red-800">Please fix the highlighted issues.</strong>
    <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-red-700">
      @foreach($errors->all() as $error)
        <li>{{ $error }}</li>
      @endforeach
    </ul>
  </div>
@endif

<form method="POST" action="{{ $action }}" class="form-stack">
  @csrf
  @if(($method ?? 'POST') !== 'POST')
    @method($method)
  @endif

  <section class="form-card">
    <div class="form-card-header">
      <div>
        <h2 class="form-card-title">Section details</h2>
        <p class="form-card-copy">Name the section, choose its scope, and place it in the syllabus order.</p>
      </div>
    </div>

    <label for="title">Section title</label>
    <input id="title" name="title" required value="{{ old('title', data_get($definition, 'title')) }}" placeholder="Attendance Policy">

    <div class="split">
      <div>
        <label for="slug">Slug</label>
        <input id="slug" name="slug" value="{{ old('slug', data_get($definition, 'slug')) }}" placeholder="attendance-policy">
      </div>
      <div>
        <label for="category">Category</label>
        <input id="category" name="category" value="{{ old('category', data_get($definition, 'category')) }}" placeholder="Policies">
      </div>
    </div>

    <div class="split">
      <div>
        <label for="scope">Scope</label>
        <select id="scope" name="scope" required>
          @php $scopeValue = old('scope', data_get($definition, 'scope', 'global')); @endphp
          <option value="global" {{ $scopeValue === 'global' ? 'selected' : '' }}>Global · same content on every syllabus</option>
          <option value="syllabus" {{ $scopeValue === 'syllabus' ? 'selected' : '' }}>Per-Syllabus · content can vary by section</option>
        </select>
      </div>
      <div>
        <label for="sort_order">Sort order</label>
        <input id="sort_order" type="number" min="0" max="10000" name="sort_order" value="{{ old('sort_order', data_get($definition, 'sort_order', 0)) }}">
      </div>
    </div>

    <label for="description">Admin notes</label>
    <textarea id="description" name="description" rows="3" placeholder="Add guidance for faculty or notes about when this section should be used.">{{ old('description', data_get($definition, 'description')) }}</textarea>
  </section>

  <section class="form-card">
    <div class="form-card-header">
      <div>
        <h2 class="form-card-title">Default content</h2>
        <p class="form-card-copy">Starter content shown in this section. Markdown is supported.</p>
      </div>
    </div>

    <label for="syllabus-structure-default-content">Default content</label>
    <textarea id="syllabus-structure-default-content" name="default_content" rows="16" placeholder="Enter the section content here.">{{ $initialMarkdown }}</textarea>
    <div id="syllabus-structure-default-content-shell" class="toast-editor-shell" style="display:none;">
      <div id="syllabus-structure-default-content-editor"></div>
    </div>
    <div id="syllabus-structure-default-content-help" class="mt-2 muted">
      Markdown editor loading...
    </div>
  </section>

  <section class="form-card">
    <div class="form-card-header">
      <div>
        <h2 class="form-card-title">Availability</h2>
        <p class="form-card-copy">Control whether the section is required, available, or protected from deletion.</p>
      </div>
    </div>

    <div class="checkbox-stack">
      <label class="checkbox-row">
        <input type="checkbox" name="is_required" value="1" {{ old('is_required', data_get($definition, 'is_required', false)) ? 'checked' : '' }}>
        <span>Required section</span>
      </label>
      <label class="checkbox-row">
        <input type="checkbox" name="is_active" value="1" {{ old('is_active', data_get($definition, 'is_active', true)) ? 'checked' : '' }}>
        <span>Active section</span>
      </label>
      <label class="checkbox-row">
        <input type="checkbox" name="is_locked" value="1" {{ old('is_locked', data_get($definition, 'is_locked', false)) ? 'checked' : '' }}>
        <span>Protected section</span>
      </label>
    </div>
  </section>

  <div class="actions">
    <button class="btn" type="submit">{{ $submitLabel }}</button>
    <a class="btn secondary" href="{{ route('aop.syllabi.index') }}">Cancel</a>
  </div>
</form>

<script src="https://uicdn.toast.com/editor/latest/toastui-editor-all.min.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    const textarea = document.getElementById('syllabus-structure-default-content');
    const shell = document.getElementById('syllabus-structure-default-content-shell');
    const editorRoot = document.getElementById('syllabus-structure-default-content-editor');
    const help = document.getElementById('syllabus-structure-default-content-help');

    if (!textarea || !shell || !editorRoot || !window.toastui || !window.toastui.Editor) {
      if (help) {
        help.textContent = 'Stored as Markdown. If the editor does not load, the textarea still works.';
      }
      return;
    }

    shell.style.display = 'block';
    textarea.style.display = 'none';
    textarea.setAttribute('aria-hidden', 'true');
    help.textContent = 'Stored as Markdown. Use the toolbar for headings, lists, tables, links, and emphasis.';

    const editor = new window.toastui.Editor({
      el: editorRoot,
      height: '480px',
      initialEditType: 'markdown',
      previewStyle: 'vertical',
      usageStatistics: false,
      initialValue: textarea.value || ''
    });

    const form = textarea.form;
    if (form) {
      form.addEventListener('submit', function () {
        textarea.value = editor.getMarkdown();
      });
    }
  });
</script>
