<x-aop-layout>
  <x-slot:title>Syllabus</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>Syllabus</h1>
      <p style="margin-top:6px;"><strong>{{ $syllabus['course_code'] }}</strong> — {{ $syllabus['course_title'] }} ({{ $syllabus['section_code'] }})</p>
      <p class="muted">{{ $term->code }} — {{ $term->name }}</p>
    </div>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.syllabi.index') }}">Back to Syllabi</a>
      <a class="btn secondary" href="{{ route('aop.syllabi.downloadHtml', $section) }}">Download HTML</a>
      <a class="btn secondary" href="{{ route('aop.syllabi.downloadJson', $section) }}">Download JSON</a>
      <a class="btn" href="#" onclick="window.open('{{ route('aop.syllabi.show', $section) }}?print=1','_blank'); return false;">Print</a>
    </div>
  </div>

  <div class="card">
    @include('aop.syllabi.partials.syllabus', ['syllabus' => $syllabus])
  </div>
</x-aop-layout>
