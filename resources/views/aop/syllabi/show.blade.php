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

  <div style="height:14px;"></div>

  <div class="card">
    <h2>Render History</h2>
    <p class="muted">Most recent renders (keeps up to 2 successful DOCX and 2 successful PDF per section per term).</p>

    @if(($history ?? collect())->count() === 0)
      <p class="muted" style="margin-top:10px;">No renders recorded yet for this section.</p>
    @else
      <table style="margin-top:10px;">
        <thead>
          <tr>
            <th style="width:140px;">When</th>
            <th style="width:90px;">Format</th>
            <th style="width:110px;">Status</th>
            <th>File</th>
            <th style="width:120px;">Size</th>
          </tr>
        </thead>
        <tbody>
          @foreach($history as $h)
            <tr>
              <td>{{ $h->created_at?->format('Y-m-d H:i') }}</td>
              <td><span class="badge">{{ strtoupper($h->format) }}</span></td>
              <td>
                @if($h->status === 'SUCCESS')
                  <span class="badge" style="background:#e6ffed; color:#0b6b2f;">SUCCESS</span>
                @else
                  <span class="badge" style="background:#ffe8e8; color:#8a0a0a;">ERROR</span>
                @endif
              </td>
              <td class="muted">
                {{ $h->storage_path ?? '—' }}
                @if($h->error_message)
                  <div style="color:#8a0a0a; margin-top:4px;">{{ $h->error_message }}</div>
                @endif
              </td>
              <td class="muted">
                @if($h->file_size)
                  {{ number_format($h->file_size / 1024, 1) }} KB
                @else
                  —
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>
</x-aop-layout>
