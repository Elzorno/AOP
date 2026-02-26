<x-aop-layout>
  <x-slot:title>Syllabi</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>Syllabi</h1>
      @if($term)
        <p style="margin-top:6px;"><strong>{{ $term->code }}</strong> — {{ $term->name }}</p>
      @else
        <p class="muted">No active term is set.</p>
      @endif
    </div>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.schedule.home') }}">Back to Schedule</a>
    </div>
  </div>

  @if(session('status'))
    <div class="card" style="border-left:4px solid #2ecc71;">
      <strong>{{ session('status') }}</strong>
    </div>
    <div style="height:10px;"></div>
  @endif

  <div class="card">
    <h2>Template</h2>
    <p class="muted">DOCX and PDF are generated from a DOCX template. Upload a new template to change formatting.</p>

    <div style="margin-top:10px; display:flex; gap:12px; align-items:center;">
      <div>
        @if($templateExists)
          <span class="badge">Template: Installed</span>
        @else
          <span class="badge" style="background:#ffe8e8;">Template: Missing</span>
        @endif
      </div>

      <form method="POST" action="{{ route('aop.syllabi.template.upload') }}" enctype="multipart/form-data" style="display:flex; gap:10px; align-items:center; margin:0;">
        @csrf
        <input type="file" name="template" accept=".docx" required>
        <button class="btn" type="submit">Upload Template</button>
      </form>
    </div>

    @error('template')
      <div class="muted" style="margin-top:8px; color:#b00020;">{{ $message }}</div>
    @enderror

    <div class="muted" style="margin-top:10px; font-size:12px;">
      Tip: Install LibreOffice in the LXC for PDF conversion: <code>apt-get install -y libreoffice</code>
    </div>
  </div>

  <div style="height:14px;"></div>

  <div class="card">
    <h2>Sections</h2>

    @if(!$term)
      <p class="muted">Set an active term to generate syllabi.</p>
    @elseif($sections->count() === 0)
      <p class="muted">No sections found for the active term.</p>
    @else
      <table style="margin-top:8px;">
        <thead>
          <tr>
            <th>Course</th>
            <th>Section</th>
            <th>Instructor</th>
            <th style="width:360px;">Outputs</th>
          </tr>
        </thead>
        <tbody>
          @foreach($sections as $s)
            <tr>
              <td>
                <strong>{{ $s->offering->catalogCourse->code }}</strong>
                <div class="muted">{{ $s->offering->catalogCourse->title }}</div>
              </td>
              <td>
                <span class="badge">{{ $s->section_code }}</span>
                <div class="muted">{{ $s->modality }}</div>
              </td>
              <td>
                {{ $s->instructor?->name ?? 'TBD' }}
                <div class="muted">{{ $s->instructor?->email ?? '' }}</div>
              </td>
              <td>
                <div class="actions" style="gap:8px; flex-wrap:wrap;">
                  <a class="btn secondary" href="{{ route('aop.syllabi.show', $s) }}">View</a>
                  <a class="btn secondary" href="{{ route('aop.syllabi.downloadHtml', $s) }}">HTML</a>
                  <a class="btn secondary" href="{{ route('aop.syllabi.downloadJson', $s) }}">JSON</a>
                  <a class="btn" href="{{ route('aop.syllabi.downloadDocx', $s) }}">DOCX</a>
                  <a class="btn" href="{{ route('aop.syllabi.downloadPdf', $s) }}">PDF</a>
                </div>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>
</x-aop-layout>
