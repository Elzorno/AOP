@php
  $print = request()->query('print') == '1';
@endphp

@if($print)
  <style>
    body { background: #fff !important; }
    .card, .row, header, nav, .actions { display: none !important; }
    .syllabi-print { display:block !important; }
  </style>
@endif

<div class="syllabi-print" style="display:block;">
  <div style="border-bottom:1px solid #ddd; padding-bottom:10px; margin-bottom:10px;">
    <h2 style="margin:0;">{{ $syllabus['course_code'] }} — {{ $syllabus['course_title'] }}</h2>
    <div class="muted" style="margin-top:4px;">
      Section: <strong>{{ $syllabus['section_code'] }}</strong>
      &nbsp;•&nbsp; Term: <strong>{{ $syllabus['term']['code'] }}</strong>
      &nbsp;•&nbsp; Modality: <strong>{{ $syllabus['modality'] }}</strong>
    </div>
  </div>

  <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
    <div>
      <h3 style="margin:0 0 6px 0;">Instructor</h3>
      @if($syllabus['instructor'])
        <div><strong>{{ $syllabus['instructor']['name'] }}</strong></div>
        <div class="muted">{{ $syllabus['instructor']['email'] }}</div>
      @else
        <div class="muted">TBD</div>
      @endif

      <h3 style="margin:12px 0 6px 0;">Office Hours</h3>
      @if(count($syllabus['office_hours']) === 0)
        <div class="muted">Not set.</div>
      @else
        <ul style="margin:0; padding-left:18px;">
          @foreach($syllabus['office_hours'] as $oh)
            <li>
              <strong>{{ implode('/', $oh['days']) }}</strong>
              {{ $oh['start'] }}–{{ $oh['end'] }}
              @if(!empty($oh['notes']))<span class="muted"> — {{ $oh['notes'] }}</span>@endif
            </li>
          @endforeach
        </ul>
      @endif
    </div>

    <div>
      <h3 style="margin:0 0 6px 0;">Course Details</h3>
      <div class="muted">Credits: <strong>{{ $syllabus['credits_text'] ?? ($syllabus['credits_min'].'-'.$syllabus['credits_max']) }}</strong></div>
      @if(!is_null($syllabus['contact_hours_per_week']))
        <div class="muted">Contact Hours/Week: <strong>{{ $syllabus['contact_hours_per_week'] }}</strong></div>
      @endif
      @if(!empty($syllabus['course_lab_fee']))
        <div class="muted">Lab Fee: <strong>${{ number_format((float)$syllabus['course_lab_fee'], 2) }}</strong></div>
      @endif

      <h3 style="margin:12px 0 6px 0;">Meeting Times</h3>
      @if(count($syllabus['meetings']) === 0)
        <div class="muted">No meeting blocks have been scheduled.</div>
      @else
        <ul style="margin:0; padding-left:18px;">
          @foreach($syllabus['meetings'] as $m)
            <li>
              <strong>{{ $m['type'] }}</strong>
              — <strong>{{ implode('/', $m['days']) }}</strong>
              {{ $m['start'] }}–{{ $m['end'] }}
              — {{ $m['room'] }}
              @if(!empty($m['notes']))<span class="muted"> — {{ $m['notes'] }}</span>@endif
            </li>
          @endforeach
        </ul>
      @endif
    </div>
  </div>

  <div style="margin-top:14px;">
    <h3>Prerequisites / Co-requisites</h3>
    <div class="muted">Prereq: {{ $syllabus['prereq'] ?: 'None listed.' }}</div>
    <div class="muted">Co-req: {{ $syllabus['coreq'] ?: 'None listed.' }}</div>
  </div>

  <div style="margin-top:14px;">
    <h3>Course Description</h3>
    <div class="muted" style="white-space:pre-wrap;">{{ $syllabus['description'] ?: 'No description.' }}</div>
    @if(!empty($syllabus['notes']))
      <div style="margin-top:8px;" class="muted"><strong>Catalog Notes:</strong> {{ $syllabus['notes'] }}</div>
    @endif
    @if(!empty($syllabus['section_notes']))
      <div style="margin-top:8px;" class="muted"><strong>Section Notes:</strong> {{ $syllabus['section_notes'] }}</div>
    @endif
  </div>

  <div style="margin-top:14px;">
    <h3>Policies</h3>
    <h4 style="margin-bottom:4px;">Attendance</h4>
    <div class="muted">{{ $syllabus['policies']['attendance'] }}</div>

    <h4 style="margin:10px 0 4px 0;">Academic Integrity</h4>
    <div class="muted">{{ $syllabus['policies']['integrity'] }}</div>

    <h4 style="margin:10px 0 4px 0;">Accommodations</h4>
    <div class="muted">{{ $syllabus['policies']['accommodations'] }}</div>
  </div>
</div>

@if($print)
  <script>window.print();</script>
@endif
