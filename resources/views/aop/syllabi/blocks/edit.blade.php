<x-aop-layout>
  <x-slot:title>Edit Syllabus Block</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>Edit Syllabus Block</h1>
      <p class="muted" style="margin-top:6px;">Update the shared syllabus content block. Changes appear in syllabus JSON packets and HTML previews immediately.</p>
    </div>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.syllabi.index') }}">Back</a>
    </div>
  </div>

  @include('aop.syllabi.blocks._form', [
    'action' => route('aop.syllabi.blocks.update', $block),
    'method' => 'PUT',
    'submitLabel' => 'Save Changes',
    'block' => $block,
  ])
</x-aop-layout>
