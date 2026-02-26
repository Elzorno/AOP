<x-aop-layout>
  <x-slot:title>Syllabi</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>Syllabi</h1>
      @if($term)
        <p style="margin-top:6px;"><strong>{{ $term->code }}</strong> — {{ $term->name }}</p>
        @if($latestPublication)
          <p class="muted">Latest published: <span class="badge">v{{ $latestPublication->version }}</span> {{ $latestPublication->published_at->format('Y-m-d H:i') }}</p>
        @else
          <p class="muted">Latest published: <span class="badge">None</span></p>
        @endif
      @else
        <p class="muted">No active term is set.</p>
      @endif
    </div>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.schedule.home') }}">Back to Schedule</a>
      @if(!$term)
        <a class="btn" href="{{ route('aop.terms.index') }}">Go to Terms</a>
      @endif
    </div>
  </div>

  @if(!$term)
    <div class="card">
      <h2>No Active Term</h2>
      <p>You must set an active term before generating syllabi.</p>
    </div>
  @else
    <div class="card">
      <h2>Syllabus Bundle</h2>
      <p class="muted">This generates a ZIP containing HTML + JSON syllabi for all sections in the active term (based on current schedule data). DOCX/PDF rendering will be added in a later phase.</p>

      @if($latestPublication)
        <form method="POST" action="{{ route('aop.syllabi.bundle', $latestPublication) }}" style="margin-top:10px;">
          @csrf
          <button class="btn" type="submit">Generate ZIP for Published v{{ $latestPublication->version }}</button>
        </form>
      @else
        <p class="muted" style="margin-top:10px;">Publish a snapshot to enable bundle generation.</p>
        <a class="btn" href="{{ route('aop.schedule.publish.index') }}">Go to Publish Snapshots</a>
      @endif
    </div>

    <div style="height:14px;"></div>

    <div class="card">
      <h2>Sections</h2>
      @if($sections->count() === 0)
        <p class="muted">No sections exist for the active term.</p>
      @else
        <table style="margin-top:8px;">
          <thead>
            <tr>
              <th style="width:120px;">Course</th>
              <th>Title</th>
              <th style="width:90px;">Section</th>
              <th style="width:180px;">Instructor</th>
              <th style="width:120px;">Modality</th>
              <th style="width:260px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            @foreach($sections as $s)
              @php $course = $s->offering->catalogCourse; @endphp
              <tr>
                <td><strong>{{ $course->code }}</strong></td>
                <td class="muted">{{ $course->title }}</td>
                <td>{{ $s->section_code }}</td>
                <td class="muted">{{ $s->instructor?->name ?? 'TBD' }}</td>
                <td class="muted">{{ $s->modality?->value ?? (string)$s->modality }}</td>
                <td>
                  <div class="actions" style="gap:8px; flex-wrap:wrap;">
                    <a class="btn secondary" href="{{ route('aop.syllabi.show', $s) }}">View</a>
                    <a class="btn secondary" href="{{ route('aop.syllabi.downloadHtml', $s) }}">HTML</a>
                    <a class="btn secondary" href="{{ route('aop.syllabi.downloadJson', $s) }}">JSON</a>
                  </div>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      @endif
    </div>
  @endif
</x-aop-layout>
