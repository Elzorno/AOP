<x-aop-layout>
  <x-slot:title>Syllabus Preview</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>Syllabus Preview</h1>
      <p class="muted" style="margin-top:6px;">
        {{ $packet['course']['code'] ?? '' }} — {{ $packet['course']['title'] ?? '' }} (Section {{ $packet['section']['code'] ?? '' }})
      </p>
    </div>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.syllabi.index') }}">Back to Syllabi</a>
      <a class="btn" href="{{ route('aop.syllabi.downloadDocx', $section) }}">DOCX</a>
      <a class="btn" href="{{ route('aop.syllabi.downloadPdf', $section) }}">PDF</a>
    </div>
  </div>

  <div class="card">
    <p class="muted">
      This is a lightweight HTML preview. The official formatting comes from the DOCX template used for DOCX/PDF output.
    </p>
    <div style="margin-top:12px;">
      <iframe srcdoc="{{ e($html) }}" style="width:100%; height:900px; border:1px solid #ddd; border-radius:10px;"></iframe>
    </div>
  </div>
</x-aop-layout>
