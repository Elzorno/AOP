<x-aop-layout>
  <x-slot:title>Edit Syllabus Structure Section</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>Edit Syllabus Structure Section</h1>
      <p class="muted" style="margin-top:6px; max-width:900px;">
        Update the shared syllabus structure definition. Global sections use this content everywhere; per-syllabus sections use it as starter content.
      </p>
    </div>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.syllabi.index') }}">Back</a>
    </div>
  </div>

  @include('aop.syllabi.structure._definition_form', [
    'action' => route('aop.syllabi.structure.update', $definition),
    'method' => 'PUT',
    'submitLabel' => 'Save Changes',
    'definition' => $definition,
  ])
</x-aop-layout>
