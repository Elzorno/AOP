@props(['activeTermLabel' => null, 'title' => null])
@php
  if ($activeTermLabel === null) {
    $activeTerm = \App\Models\Term::query()->where('is_active', true)->first();
    $activeTermLabel = $activeTerm
      ? 'Active Term: '.$activeTerm->code.' - '.$activeTerm->name
      : 'No active term selected';
  }
@endphp
@include('aop.layout', ['activeTermLabel' => $activeTermLabel, 'title' => $title, 'slot' => $slot])
