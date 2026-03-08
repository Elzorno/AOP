<x-aop-layout>
  <x-slot:title>New Syllabus Structure Section</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>New Syllabus Structure Section</h1>
      <p class="muted" style="margin-top:6px; max-width:900px;">
        Define a section that belongs in the syllabus structure. Choose whether it is global for every syllabus or editable per syllabus,
        then set the default starter content and display order.
      </p>
    </div>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.syllabi.index') }}">Back</a>
    </div>
  </div>

  @include('aop.syllabi.structure._definition_form', [
    'action' => route('aop.syllabi.structure.store'),
    'method' => 'POST',
    'submitLabel' => 'Create Section',
    'definition' => null,
  ])
</x-aop-layout>
