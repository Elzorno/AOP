<x-aop-layout>
  <x-slot:title>New Syllabus Block</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>New Syllabus Block</h1>
      <p class="muted" style="margin-top:6px;">Create a shared syllabus block that can be reused across all section syllabi.</p>
    </div>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.syllabi.index') }}">Back</a>
    </div>
  </div>

  @include('aop.syllabi.blocks._form', [
    'action' => route('aop.syllabi.blocks.store'),
    'method' => 'POST',
    'submitLabel' => 'Create Block',
    'block' => null,
  ])
</x-aop-layout>
