@php
  $heading = strtoupper('Room ' . $room->name . ' | ' . ($term->name ?: $term->code));
  $entitySubtitle = trim((string)($room->building ?? ''));
@endphp

@if($isPrint)
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <meta name="robots" content="noindex,nofollow" />
  <title>Room Grid - {{ $term->code }} - {{ $room->name }}</title>
  @vite(['resources/css/app.css'])
</head>
<body class="bg-white text-slate-900">
  <x-aop-schedule-grid
    :heading="$heading"
    :term="$term"
    :entity-label="$entitySubtitle"
    :days="$days"
    :grid="$grid"
    :start="$start"
    :is-print="true"
    :is-pdf="$isPdf ?? false"
    :auto-print="$autoPrint ?? true"
    empty-message="No schedule data for this room in the active term."
  />
</body>
</html>
@else
<x-aop-layout>
  <x-slot:title>{{ $heading }}</x-slot:title>

  <x-aop-schedule-grid
    :heading="$heading"
    :term="$term"
    :entity-label="$entitySubtitle"
    description="Includes class meetings and open labs. Print opens a letter-size landscape sheet and hides unused days when possible."
    :days="$days"
    :grid="$grid"
    :start="$start"
    empty-message="No schedule data for this room in the active term."
  >
    <x-slot:actions>
      <a class="btn secondary" href="{{ route('aop.schedule.grids.index', $term) }}">Back to Grids</a>
      <a class="btn" href="{{ route('aop.rooms.index') }}">Rooms</a>
      <a class="btn" href="{{ route('aop.schedule.sections.index', $term) }}">Sections</a>
      <a class="btn" href="{{ route('aop.schedule.grids.room', [$term, $room, 'print' => 1]) }}" target="_blank">Print</a>
      <a class="btn" href="{{ route('aop.schedule.grids.room.pdf', [$term, $room]) }}">Export PDF</a>
    </x-slot:actions>
  </x-aop-schedule-grid>
</x-aop-layout>
@endif
