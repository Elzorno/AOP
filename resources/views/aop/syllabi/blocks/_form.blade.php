@php
  $initialMarkdown = old('content_html', data_get($block, 'content_html'));
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
        <h2 class="form-card-title">Block details</h2>
        <p class="form-card-copy">Name the block and organize it for later reuse.</p>
      </div>
    </div>

    <label for="title">Block title</label>
    <input id="title" name="title" required value="{{ old('title', data_get($block, 'title')) }}" placeholder="Attendance Policy">

    <div class="split">
      <div>
        <label for="category">Category</label>
        <input id="category" name="category" value="{{ old('category', data_get($block, 'category')) }}" placeholder="Policies">
      </div>
      <div>
        <label for="version">Version</label>
        <input id="version" name="version" value="{{ old('version', data_get($block, 'version')) }}" placeholder="2026.1">
      </div>
    </div>
  </section>

  <section class="form-card">
    <div class="form-card-header">
      <div>
        <h2 class="form-card-title">Block content</h2>
        <p class="form-card-copy">Markdown content shared across syllabus previews and exports.</p>
      </div>
    </div>

    <label for="syllabus-block-content">Block content</label>
    <textarea id="syllabus-block-content" name="content_html" rows="16" placeholder="Enter the syllabus block content here.">{{ $initialMarkdown }}</textarea>
    <div id="syllabus-block-editor-shell" class="toast-editor-shell" style="display:none;">
      <div id="syllabus-block-editor"></div>
    </div>
    <div id="syllabus-block-editor-help" class="mt-2 muted">
      Markdown editor loading...
    </div>
  </section>

  <section class="form-card">
    <div class="form-card-header">
      <div>
        <h2 class="form-card-title">Protection</h2>
        <p class="form-card-copy">Prevent deletion while the block is still in use.</p>
      </div>
    </div>

    <div class="checkbox-stack">
      <label class="checkbox-row">
        <input type="checkbox" name="is_locked" value="1" {{ old('is_locked', data_get($block, 'is_locked', false)) ? 'checked' : '' }}>
        <span>Protected block</span>
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
    const textarea = document.getElementById('syllabus-block-content');
    const shell = document.getElementById('syllabus-block-editor-shell');
    const editorRoot = document.getElementById('syllabus-block-editor');
    const help = document.getElementById('syllabus-block-editor-help');

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
