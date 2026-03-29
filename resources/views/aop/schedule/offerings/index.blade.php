<x-aop-layout :activeTermLabel="'Active Term: '.$term->code.' - '.$term->name">
  <x-slot:title>Offerings</x-slot:title>

  @php
    $emptyOfferings = $offerings->where('sections_count', 0)->count();
    $offeringsWithSections = $offerings->where('sections_count', '>', 0)->count();
  @endphp

  <div class="page-shell">
    <section class="page-header">
      <span class="page-eyebrow">Schedule Coverage</span>
      <h1 class="page-title">Offering lineup for {{ $term->code }}</h1>
      <p class="page-subtitle">Review active-term offerings and open the ones that need sections.</p>

      <div class="summary-strip">
        <div class="summary-stat">
          <div class="summary-stat-label">Offerings</div>
          <div class="summary-stat-value">{{ $offerings->count() }}</div>
          <div class="summary-stat-note">Courses already scheduled this term.</div>
        </div>
        <div class="summary-stat">
          <div class="summary-stat-label">With Sections</div>
          <div class="summary-stat-value">{{ $offeringsWithSections }}</div>
          <div class="summary-stat-note">Offerings that already have at least one section.</div>
        </div>
        <div class="summary-stat">
          <div class="summary-stat-label">Empty Offerings</div>
          <div class="summary-stat-value">{{ $emptyOfferings }}</div>
          <div class="summary-stat-note">Good candidates for the next section you add.</div>
        </div>
        <div class="summary-stat">
          <div class="summary-stat-label">Schedule State</div>
          <div class="summary-stat-value">{{ $term->schedule_locked ? 'Locked' : 'Open' }}</div>
          <div class="summary-stat-note">{{ $term->schedule_locked ? 'Creation is paused until the term is unlocked.' : 'Offerings and sections can still be updated.' }}</div>
        </div>
      </div>

      <div class="toolbar-line">
        <a class="btn" href="{{ route('aop.schedule.home') }}">Open Schedule Studio</a>
        <a class="btn secondary" href="{{ route('aop.schedule.readiness.index') }}">Review Readiness</a>
        <a class="btn secondary" href="{{ route('aop.schedule.offerings.create') }}">Fallback Create Form</a>
      </div>
    </section>

    @if($offerings->isEmpty())
      <section class="workspace-card">
        <div class="workspace-header">
          <div>
            <h2 class="workspace-title">No offerings yet</h2>
            <p class="workspace-copy">Add the first offering to start the term schedule.</p>
          </div>
          <div class="actions">
            <a class="btn" href="{{ route('aop.schedule.home') }}">Create in Studio</a>
          </div>
        </div>
      </section>
    @else
      <section class="directory-grid">
        @foreach($offerings as $offering)
          @php $course = $offering->catalogCourse; @endphp
          <article class="directory-card {{ $offering->sections_count === 0 ? 'directory-card-danger' : '' }}">
            <div class="offering-head">
              <div>
                <div class="offering-kicker">Offering</div>
                <h2 class="offering-title">{{ $course?->code ?? 'Course' }} - {{ $course?->title ?? 'Untitled course' }}</h2>
                <p class="offering-copy">{{ $offering->delivery_method ?: 'Delivery method not set yet.' }}</p>
              </div>
              <div class="section-badges">
                <span class="badge info">{{ $offering->sections_count }} section{{ $offering->sections_count === 1 ? '' : 's' }}</span>
                @if($offering->sections_count === 0)
                  <span class="badge warn">Needs sections</span>
                @else
                  <span class="badge success">In progress</span>
                @endif
              </div>
            </div>

            <div class="directory-meta">
              <div class="offering-meta-card">
                <strong class="block text-slate-900">Credits</strong>
                <span>{{ $course?->credits_text ?: ($course?->credits ? number_format((float) $course->credits, 2).' credits' : '—') }}</span>
              </div>
              <div class="offering-meta-card">
                <strong class="block text-slate-900">Prerequisite</strong>
                <span>{{ $offering->prereq_override ?: ($course?->prereq_text ?: '—') }}</span>
              </div>
              <div class="offering-meta-card">
                <strong class="block text-slate-900">Corequisite</strong>
                <span>{{ $offering->coreq_override ?: ($course?->coreq_text ?: '—') }}</span>
              </div>
            </div>

            @if($offering->notes)
              <div class="status-note mt-4">{{ $offering->notes }}</div>
            @endif

            <div class="section-actions">
              <a class="btn" href="{{ route('aop.schedule.home', ['focus_offering' => $offering->id]) }}#offering-{{ $offering->id }}">
                {{ $offering->sections_count === 0 ? 'Add First Section' : 'Open' }}
              </a>
              <a class="btn secondary" href="{{ route('aop.schedule.sections.index', ['q' => $course?->code]) }}">Sections</a>
            </div>
          </article>
        @endforeach
      </section>
    @endif
  </div>
</x-aop-layout>
