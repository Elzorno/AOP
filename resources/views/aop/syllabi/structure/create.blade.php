<x-aop-layout>
  <x-slot:title>New Syllabus Structure Section</x-slot:title>

  <div class="page-shell">
    <section class="briefing-grid">
      <div class="briefing-panel briefing-panel-strong">
        <div class="briefing-kicker">Structure builder</div>
        <h1 class="briefing-title">Create a syllabus structure section.</h1>
        <p class="briefing-copy">Add a shared section, choose whether it stays global or can be edited per syllabus, and set its default content.</p>

        <div class="mt-8 quick-actions">
          <a class="btn secondary" href="{{ route('aop.syllabi.index') }}">Back to Syllabi</a>
        </div>
      </div>

      <aside class="briefing-sidebar">
        <div class="briefing-kicker">Scope options</div>
        <h2 class="watchlist-title">Choose the right level</h2>
        <p class="watchlist-copy">Global sections stay the same everywhere. Per-syllabus sections can be adjusted per section.</p>

        <div class="watchlist-group">
          <div class="watchlist-item">
            <div>
              <div class="watchlist-name">Global</div>
              <div class="watchlist-note">Same content on every syllabus</div>
            </div>
            <span class="watchlist-value good">Shared</span>
          </div>
          <div class="watchlist-item">
            <div>
              <div class="watchlist-name">Per-Syllabus</div>
              <div class="watchlist-note">Editable for each section syllabus</div>
            </div>
            <span class="watchlist-value warn">Flexible</span>
          </div>
        </div>
      </aside>
    </section>

    @include('aop.syllabi.structure._definition_form', [
      'action' => route('aop.syllabi.structure.store'),
      'method' => 'POST',
      'submitLabel' => 'Create Section',
      'definition' => null,
    ])
  </div>
</x-aop-layout>
