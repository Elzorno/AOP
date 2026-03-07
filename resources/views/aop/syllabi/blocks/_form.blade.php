@php
  $initialMarkdown = old('content_html', data_get($block, 'content_html'));
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

    <label>Block Title</label>
    <input name="title" required value="{{ old('title', data_get($block, 'title')) }}" placeholder="Attendance Policy" />

    <div class="split">
      <div>
        <label>Category (optional)</label>
        <input name="category" value="{{ old('category', data_get($block, 'category')) }}" placeholder="Policies" />
      </div>
      <div>
        <label>Version (optional)</label>
        <input name="version" value="{{ old('version', data_get($block, 'version')) }}" placeholder="2026.1" />
      </div>
    </div>

    <label>Block Content</label>
    <textarea id="syllabus-block-content" name="content_html" rows="16" placeholder="Enter the syllabus block text here. Use Markdown for headings, lists, emphasis, and links.">{{ $initialMarkdown }}</textarea>
    <div id="syllabus-block-editor-shell" class="toast-editor-shell" style="display:none;">
      <div id="syllabus-block-editor"></div>
    </div>
    <div id="syllabus-block-editor-help" class="muted" style="margin-top:6px; font-size:12px;">
      Stored as Markdown. If the rich editor does not load, the textarea above still works.
    </div>

    <label style="display:flex; align-items:center; gap:8px; margin-top:14px;">
      <input type="checkbox" name="is_locked" value="1" {{ old('is_locked', data_get($block, 'is_locked', false)) ? 'checked' : '' }} />
      Mark as protected (prevents deletion until unchecked)
    </label>

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
    const textarea = document.getElementById('syllabus-block-content');
    const shell = document.getElementById('syllabus-block-editor-shell');
    const editorRoot = document.getElementById('syllabus-block-editor');
    const help = document.getElementById('syllabus-block-editor-help');

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
