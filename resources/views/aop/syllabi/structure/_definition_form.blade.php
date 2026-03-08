@php
  $initialMarkdown = old('default_content', data_get($definition, 'default_content'));
@endphp

<link rel="stylesheet" href="https://uicdn.toast.com/editor/latest/toastui-editor.min.css">
@if($errors->any())
  <div class="card" style="border-left:4px solid #b00020; margin-bottom:12px;">
    <strong>Please fix the highlighted issues.</strong>
    <ul style="margin:8px 0 0 18px;">
      @foreach($errors->all() as $error)
        <li>{{ $error }}</li>
      @endforeach
    </ul>
  </div>
@endif

<div class="card">
  <form method="POST" action="{{ $action }}">
    @csrf
    @if(($method ?? 'POST') !== 'POST')
      @method($method)
    @endif

    <label>Section Title</label>
    <input name="title" required value="{{ old('title', data_get($definition, 'title')) }}" placeholder="Attendance Policy" />

    <div class="split">
      <div>
        <label>Slug (optional)</label>
        <input name="slug" value="{{ old('slug', data_get($definition, 'slug')) }}" placeholder="attendance-policy" />
      </div>
      <div>
        <label>Category (optional)</label>
        <input name="category" value="{{ old('category', data_get($definition, 'category')) }}" placeholder="Policies" />
      </div>
    </div>

    <div class="split">
      <div>
        <label>Scope</label>
        <select name="scope" required>
          @php $scopeValue = old('scope', data_get($definition, 'scope', 'global')); @endphp
          <option value="global" {{ $scopeValue === 'global' ? 'selected' : '' }}>Global — same content on every syllabus</option>
          <option value="syllabus" {{ $scopeValue === 'syllabus' ? 'selected' : '' }}>Per-Syllabus — content can vary by section syllabus</option>
        </select>
      </div>
      <div>
        <label>Sort Order</label>
        <input type="number" min="0" max="10000" name="sort_order" value="{{ old('sort_order', data_get($definition, 'sort_order', 0)) }}" />
      </div>
    </div>

    <label>Description / Admin Notes (optional)</label>
    <textarea name="description" rows="3" placeholder="How this section should be used, when it applies, or what faculty should customize.">{{ old('description', data_get($definition, 'description')) }}</textarea>

    <label>Default Content</label>
    <textarea id="syllabus-structure-default-content" name="default_content" rows="16" placeholder="Enter default content or starter text here. Use Markdown for headings, lists, emphasis, and links.">{{ $initialMarkdown }}</textarea>
    <div id="syllabus-structure-default-content-shell" class="toast-editor-shell" style="display:none;">
      <div id="syllabus-structure-default-content-editor"></div>
    </div>
    <div id="syllabus-structure-default-content-help" class="muted" style="margin-top:6px; font-size:12px;">
      Stored as Markdown. If the rich editor does not load, the textarea above still works.
    </div>

    <div style="display:grid; gap:8px; margin-top:14px;">
      <label style="display:flex; align-items:center; gap:8px;">
        <input type="checkbox" name="is_required" value="1" {{ old('is_required', data_get($definition, 'is_required', false)) ? 'checked' : '' }} />
        Required section (cannot be hidden on per-syllabus records)
      </label>
      <label style="display:flex; align-items:center; gap:8px;">
        <input type="checkbox" name="is_active" value="1" {{ old('is_active', data_get($definition, 'is_active', true)) ? 'checked' : '' }} />
        Active section (appears in structure and preview)
      </label>
      <label style="display:flex; align-items:center; gap:8px;">
        <input type="checkbox" name="is_locked" value="1" {{ old('is_locked', data_get($definition, 'is_locked', false)) ? 'checked' : '' }} />
        Protected section (prevents deletion until unchecked)
      </label>
    </div>

    <div style="height:12px;"></div>
    <div class="actions">
      <button class="btn" type="submit">{{ $submitLabel }}</button>
      <a class="btn secondary" href="{{ route('aop.syllabi.index') }}">Cancel</a>
    </div>
  </form>
</div>

<script src="https://uicdn.toast.com/editor/latest/toastui-editor-all.min.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    const textarea = document.getElementById('syllabus-structure-default-content');
    const shell = document.getElementById('syllabus-structure-default-content-shell');
    const editorRoot = document.getElementById('syllabus-structure-default-content-editor');
    const help = document.getElementById('syllabus-structure-default-content-help');

    if (!textarea || !shell || !editorRoot || !window.toastui || !window.toastui.Editor) {
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
