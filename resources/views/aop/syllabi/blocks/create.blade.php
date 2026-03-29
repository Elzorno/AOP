<x-aop-layout>
  <x-slot:title>New Syllabus Block</x-slot:title>

  <div class="page-shell">
    <section class="briefing-grid">
      <div class="briefing-panel briefing-panel-strong">
        <div class="briefing-kicker">Shared blocks</div>
        <h1 class="briefing-title">Create a shared syllabus block.</h1>
        <p class="briefing-copy">Add reusable content that can appear in syllabus previews and export packets.</p>

        <div class="mt-8 quick-actions">
          <a class="btn secondary" href="{{ route('aop.syllabi.index') }}">Back to Syllabi</a>
        </div>
      </div>

      <aside class="briefing-sidebar">
        <div class="briefing-kicker">Block use</div>
        <h2 class="watchlist-title">Shared content</h2>
        <p class="watchlist-copy">Use shared blocks for content that should stay reusable across multiple syllabi.</p>
      </aside>
    </section>

    @include('aop.syllabi.blocks._form', [
      'action' => route('aop.syllabi.blocks.store'),
      'method' => 'POST',
      'submitLabel' => 'Create Block',
      'block' => null,
    ])
  </div>
</x-aop-layout>
