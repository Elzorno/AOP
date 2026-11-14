@props([
    'heading',
    'term',
    'entityLabel' => null,
    'description' => null,
    'rightHeading' => 'Weekly Class Schedule',
    'footerNote' => 'Scheduled room use only. Unscheduled/direct-supervision courses are omitted.',
    'isPdf' => false,
    'autoPrint' => true,
    'days' => [],
    'grid' => [],
    'start' => '08:00',
    'isPrint' => false,
    'emptyMessage' => 'No schedule data for the selected record in the active term.',
])

@php
    $slots = (int) ($grid['slots'] ?? 0);
    $slotMinutes = (int) ($grid['slot_minutes'] ?? 30);
    $slotHeightPx = $isPrint ? ($isPdf ? 16 : 24) : 38;
    $slotBorderPx = 1;
    $baseCellPadYPx = $isPrint ? ($isPdf ? 2 : 4) : 6;
    $filledCellPadYPx = $isPrint ? ($isPdf ? 1 : 3) : 4;
    $printPageWidth = $isPdf ? '8in' : '10.3in';
    $printPageHeight = $isPdf ? '10.5in' : '7.8in';
    $printPageMargin = $isPdf ? '0.25in' : '0.35in';
    $printShellPadding = $isPdf ? 4 : 8;

    [$sh, $sm] = array_map('intval', explode(':', $start));
    $startTotal = ($sh * 60) + $sm;

    $visibleDays = $days;
    if ($isPrint) {
        $usedDays = [];

        foreach ($days as $day) {
            foreach (($grid['cells'][$day] ?? []) as $cell) {
                if (is_array($cell) && (($cell['type'] ?? null) === 'cell')) {
                    $usedDays[] = $day;
                    break;
                }
            }
        }

        if (count($usedDays) > 0) {
            $visibleDays = $usedDays;
        }
    }

    $hasActions = isset($actions) && trim((string) $actions) !== '';

    $fmt = function (int $minutes): string {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return sprintf('%02d:%02d', $h, $m);
    };

    $timeToMinutes = function (string $hhmm): int {
        [$h, $m] = array_map('intval', explode(':', $hhmm));

        return ($h * 60) + $m;
    };

      $formatClock = function (string $hhmm) use ($timeToMinutes): string {
        $minutes = $timeToMinutes($hhmm);
        $hour24 = intdiv($minutes, 60);
        $min = $minutes % 60;
        $suffix = $hour24 >= 12 ? 'PM' : 'AM';
        $hour12 = $hour24 % 12;
        $hour12 = $hour12 === 0 ? 12 : $hour12;

        return sprintf('%d:%02d %s', $hour12, $min, $suffix);
      };

      $formatHourTick = function (int $minutes): string {
        $hour24 = intdiv($minutes, 60);
        $suffix = $hour24 >= 12 ? 'PM' : 'AM';
        $hour12 = $hour24 % 12;
        $hour12 = $hour12 === 0 ? 12 : $hour12;

        return sprintf('%d %s', $hour12, $suffix);
      };

      $termDateRange = null;
      if (!empty($term->starts_on) && !empty($term->ends_on)) {
        $termDateRange = sprintf(
          '%s - %s, %s',
          $term->starts_on->format('F j'),
          $term->ends_on->format('F j'),
          $term->ends_on->format('Y')
        );
      }

    $fullCellHeightPx = function (int $rowspan) use ($slotHeightPx, $slotBorderPx, $baseCellPadYPx, $filledCellPadYPx): int {
        return max(
            1,
            (($slotHeightPx + ($baseCellPadYPx * 2)) * $rowspan)
                + ($slotBorderPx * max(0, $rowspan - 1))
                - ($filledCellPadYPx * 2)
        );
    };

    $singleEventLayout = function (array $event, string $cellStart, int $rowspan) use ($timeToMinutes, $slotMinutes, $fullCellHeightPx): array {
        $reservedMinutes = max($slotMinutes, $rowspan * $slotMinutes);
        $eventStart = $timeToMinutes($event['starts_at']);
        $eventEnd = $timeToMinutes($event['ends_at']);
        $cellStartMinutes = $timeToMinutes($cellStart);

        $offsetMinutes = max(0, min($reservedMinutes, $eventStart - $cellStartMinutes));
        $durationMinutes = max(1, $eventEnd - $eventStart);
        $durationMinutes = min($durationMinutes, max(1, $reservedMinutes - $offsetMinutes));

        $fullHeightPx = $fullCellHeightPx($rowspan);
        $topPx = $fullHeightPx * ($offsetMinutes / $reservedMinutes);
        $heightPx = max(20, $fullHeightPx * ($durationMinutes / $reservedMinutes));
        $heightPx = min($fullHeightPx - $topPx, $heightPx);

        return [
            'stack_height' => sprintf('%.3Fpx', $fullHeightPx),
            'top' => sprintf('%.3Fpx', $topPx),
            'height' => sprintf('%.3Fpx', $heightPx),
        ];
    };
@endphp

<style>
  .schedule-grid-sheet {
    --print-page-width: {{ $printPageWidth }};
    --print-page-height: {{ $printPageHeight }};
    --print-shell-padding: {{ $printShellPadding }}px;
    --slot-height: {{ $slotHeightPx }}px;
    --slot-border: {{ $slotBorderPx }}px;
    --time-col-width: {{ $isPrint ? ($isPdf ? 42 : 58) : 80 }}px;
    --event-font-size: {{ $isPrint ? ($isPdf ? '7px' : '9px') : '13px' }};
    --event-time-font-size: {{ $isPrint ? ($isPdf ? '6px' : '8px') : '11px' }};
    color: #1e293b;
  }

  .schedule-grid-sheet .schedule-grid-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
  }

  .schedule-grid-sheet .schedule-grid-header-main {
    min-width: 0;
  }

  .schedule-grid-sheet .schedule-grid-right-label {
    text-transform: uppercase;
    letter-spacing: 0.08em;
    font-size: {{ $isPrint ? ($isPdf ? '8px' : '10px') : '11px' }};
    font-weight: 700;
    color: #64748b;
    margin: 0;
    white-space: nowrap;
  }

  .schedule-grid-sheet .schedule-grid-title {
    margin: 0;
    text-transform: uppercase;
    letter-spacing: 0.02em;
    color: #1f3655;
    font-weight: 800;
    font-size: {{ $isPrint ? ($isPdf ? '22px' : '34px') : '28px' }};
    line-height: 1.05;
  }

  .schedule-grid-sheet .schedule-grid-subtitle {
    margin: 6px 0 0;
    color: #64748b;
    font-size: {{ $isPrint ? ($isPdf ? '9px' : '12px') : '13px' }};
    line-height: 1.25;
  }

  .schedule-grid-sheet .schedule-grid-note {
    margin-top: 8px;
    max-width: 70ch;
  }

  .schedule-grid-sheet .schedule-grid-card {
    overflow: auto;
  }

  .schedule-grid-sheet .sched-grid {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    background: #fff;
    border: 1px solid #cfd8e3;
  }

  .schedule-grid-sheet .sched-grid th,
  .schedule-grid-sheet .sched-grid td {
    border: 1px solid #d9e1ea;
    padding: {{ $baseCellPadYPx }}px 8px;
  }

  .schedule-grid-sheet .sched-grid th {
    background: #1f3b5a;
    color: #f8fafc;
    font-size: {{ $isPrint ? ($isPdf ? '7px' : '9px') : '14px' }};
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.03em;
  }

  .schedule-grid-sheet:not(.schedule-grid-sheet--print) .sched-grid th {
    position: sticky;
    top: 0;
    z-index: 2;
  }

  .schedule-grid-sheet .time-col {
    width: var(--time-col-width);
    background: #f8fbff;
    color: #64748b;
    font-size: {{ $isPrint ? ($isPdf ? '6px' : '8px') : '12px' }};
    font-weight: 600;
  }

  .schedule-grid-sheet:not(.schedule-grid-sheet--print) .time-col {
    position: sticky;
    left: 0;
    z-index: 1;
  }

  .schedule-grid-sheet .slot {
    height: var(--slot-height);
  }

  .schedule-grid-sheet .slot--filled {
    padding: {{ $filledCellPadYPx }}px !important;
    vertical-align: top;
  }

  .schedule-grid-sheet .slot-stack {
    position: relative;
    height: var(--slot-fill-height);
    min-height: var(--slot-fill-height);
    display: flex;
    flex-direction: column;
    gap: {{ $isPrint ? 2 : 4 }}px;
  }

  .schedule-grid-sheet .slot-stack--single {
    display: block;
  }

  .schedule-grid-sheet .event {
    border: 1px solid var(--event-border, #c8d4e0);
    border-radius: {{ $isPrint ? ($isPdf ? 4 : 6) : 10 }}px;
    padding: {{ $isPrint ? ($isPdf ? '2px 3px' : '4px 5px') : '6px 8px' }};
    margin: 0;
    background: var(--event-bg, #eef3f9);
    display: flex;
    flex: 1 1 0;
    min-height: 0;
    flex-direction: column;
    justify-content: flex-start;
    gap: 2px;
    overflow: hidden;
  }

  .schedule-grid-sheet .event--single {
    position: absolute;
    left: 0;
    right: 0;
    top: var(--event-top, 0px);
    height: var(--event-height, 100%);
  }

  .schedule-grid-sheet .event__label {
    font-size: var(--event-font-size);
    font-weight: 700;
    line-height: 1.2;
    word-break: break-word;
    color: #0f172a;
  }

  .schedule-grid-sheet .event__title {
    font-size: var(--event-time-font-size);
    font-weight: 600;
    line-height: 1.2;
    color: #334155;
    word-break: break-word;
  }

  .schedule-grid-sheet .event__meta {
    font-size: var(--event-time-font-size);
    line-height: 1.2;
    color: #475569;
    margin-top: auto;
  }

  .schedule-grid-sheet .event__time {
    display: block;
    margin-top: 1px;
    color: #475569;
    font-size: var(--event-time-font-size);
    font-weight: 600;
    line-height: 1.2;
  }

  .schedule-grid-sheet .event.office {
    background: #eef6ff;
    border-color: #bdd2e8;
  }

  .schedule-grid-sheet .event.class {
    background: var(--event-bg, #edf3fb);
  }

  .schedule-grid-sheet .event.open-lab {
    background: var(--event-bg, #fff2db);
    border-color: var(--event-border, #e7c48b);
  }

  .schedule-grid-sheet .event--tone-0 { --event-bg: #e8f0fb; --event-border: #b9cde9; }
  .schedule-grid-sheet .event--tone-1 { --event-bg: #e7f4eb; --event-border: #b8dfc3; }
  .schedule-grid-sheet .event--tone-2 { --event-bg: #f8efdb; --event-border: #e9d0a5; }
  .schedule-grid-sheet .event--tone-3 { --event-bg: #f8e7e3; --event-border: #e6bcb4; }
  .schedule-grid-sheet .event--tone-4 { --event-bg: #ece9fb; --event-border: #ccc3ec; }
  .schedule-grid-sheet .event--tone-5 { --event-bg: #e6f1f5; --event-border: #b7d2df; }
  .schedule-grid-sheet .event--tone-6 { --event-bg: #f1eee6; --event-border: #d8cdb7; }
  .schedule-grid-sheet .event--tone-7 { --event-bg: #f0f6e7; --event-border: #c8dcb0; }

  .schedule-grid-sheet .muted {
    color: var(--muted);
    font-size: {{ $isPrint ? '9px' : '12px' }};
  }

  .schedule-grid-sheet--print {
    box-sizing: border-box;
    width: var(--print-page-width);
    height: var(--print-page-height);
    margin: 0 auto;
    display: grid;
    grid-template-rows: auto minmax(0, 1fr);
    gap: 8px;
    background: #fff;
    page-break-inside: avoid;
    break-inside: avoid-page;
  }

  .schedule-grid-sheet--print .schedule-grid-header {
    margin-bottom: 0;
    gap: 10px;
    align-items: start;
  }

  .schedule-grid-sheet--print .schedule-grid-title {
    font-size: {{ $isPdf ? '22px' : '48px' }};
    line-height: 1.1;
  }

  .schedule-grid-sheet--print .schedule-grid-header p {
    margin-bottom: 0;
    line-height: 1.2;
  }

  .schedule-grid-sheet--print .schedule-grid-right-label {
    margin-top: 8px;
  }

  .schedule-grid-sheet--print .schedule-grid-note {
    margin-top: 4px;
    font-size: {{ $isPdf ? '7px' : '8.5px' }};
    max-width: none;
  }

  .schedule-grid-sheet--print .schedule-grid-print-shell {
    box-sizing: border-box;
    min-height: 0;
    overflow: hidden;
    border: 1px solid #cbd5e1;
    border-radius: 12px;
    padding: var(--print-shell-padding);
    page-break-inside: avoid;
    break-inside: avoid-page;
  }

  .schedule-grid-sheet--print .schedule-grid-print-frame {
    width: 100%;
    height: 100%;
    overflow: hidden;
  }

  .schedule-grid-sheet--print .schedule-grid-print-canvas {
    transform-origin: top left;
    width: 100%;
  }

  .schedule-grid-sheet--print .sched-grid th,
  .schedule-grid-sheet--print .sched-grid td {
    padding: {{ $baseCellPadYPx }}px 4px;
  }

  .schedule-grid-sheet--print .sched-grid tbody tr {
    break-inside: avoid;
    page-break-inside: avoid;
  }

  @if($isPdf)
  .schedule-grid-sheet--print {
    width: 100%;
    height: auto;
    margin: 0;
    display: block;
    gap: 0;
    page-break-inside: auto;
    break-inside: auto;
  }

  .schedule-grid-sheet--print .schedule-grid-header {
    margin-bottom: 4px !important;
  }

  .schedule-grid-sheet--print .schedule-grid-print-shell {
    overflow: visible;
    border-radius: 6px;
    page-break-inside: auto;
    break-inside: auto;
  }

  .schedule-grid-sheet--print .schedule-grid-print-frame {
    height: auto;
    overflow: visible;
  }

  .schedule-grid-sheet--print .schedule-grid-print-canvas {
    transform: none !important;
    width: 100%;
  }

  .schedule-grid-sheet--print .schedule-grid-note {
    margin-top: 3px;
  }
  @endif

  @media print {
    @page {
      size: letter {{ $isPdf ? 'portrait' : 'landscape' }};
      margin: {{ $printPageMargin }};
    }

    html,
    body {
      margin: 0;
      background: #fff !important;
      color: #0f172a;
    }

    .schedule-grid-sheet--print {
      width: var(--print-page-width);
      height: var(--print-page-height);
    }

    .schedule-grid-sheet--print .schedule-grid-print-shell {
      border-color: #cbd5e1 !important;
      box-shadow: none !important;
    }
  }
</style>

<section class="schedule-grid-sheet {{ $isPrint ? 'schedule-grid-sheet--print' : '' }}">
  <div class="row schedule-grid-header" style="margin-bottom: {{ $isPrint ? '0' : '14px' }};">
    <div class="schedule-grid-header-main">
      <h1 class="schedule-grid-title">{{ $heading }}</h1>
      <p class="schedule-grid-subtitle">
        @if($entityLabel)
          {{ $entityLabel }}
        @endif
        @if($entityLabel && $termDateRange)
          •
        @endif
        @if($termDateRange)
          {{ $termDateRange }}
        @else
          {{ $term->name }}
        @endif
      </p>
      @if($description)
        <p class="muted schedule-grid-note">{{ $description }}</p>
      @endif
    </div>

    <p class="schedule-grid-right-label">{{ strtoupper($rightHeading) }}</p>

    @if(!$isPrint && $hasActions)
      <div class="actions">
        {{ $actions }}
      </div>
    @endif
  </div>

  <div class="{{ $isPrint ? 'schedule-grid-print-shell' : 'card schedule-grid-card' }}">
    @if ($slots <= 0)
      <p>{{ $emptyMessage }}</p>
    @else
      <div class="{{ $isPrint ? 'schedule-grid-print-frame' : '' }}" @if($isPrint) data-print-frame @endif>
        <div class="{{ $isPrint ? 'schedule-grid-print-canvas' : '' }}" @if($isPrint) data-print-grid @endif>
          <table class="sched-grid">
            <thead>
              <tr>
                <th class="time-col">Time</th>
                @foreach ($visibleDays as $day)
                  <th>{{ $day }}</th>
                @endforeach
              </tr>
            </thead>
            <tbody>
              @for ($i = 0; $i < $slots; $i++)
                @php
                  $rowMinutes = $startTotal + ($i * $slotMinutes);
                  $rowTime = $fmt($rowMinutes);
                  $showTimeLabel = ($rowMinutes % 60) === 0;
                  $rowTick = $showTimeLabel ? $formatHourTick($rowMinutes) : '';
                @endphp
                <tr>
                  <td class="time-col"><span class="muted">{{ $rowTick }}</span></td>

                  @foreach ($visibleDays as $day)
                    @php $cell = $grid['cells'][$day][$i]; @endphp

                    @if (is_array($cell) && (($cell['type'] ?? null) === 'skip'))
                      @continue
                    @endif

                    @if (is_array($cell) && (($cell['type'] ?? null) === 'cell'))
                      @php
                        $rowspan = max(1, (int) ($cell['rowspan'] ?? 1));
                        $eventsInCell = $cell['events'] ?? [];
                        $isSingleEvent = count($eventsInCell) === 1;
                        $singleLayout = $isSingleEvent ? $singleEventLayout($eventsInCell[0], $rowTime, $rowspan) : null;
                        $fillHeight = $isSingleEvent
                          ? $singleLayout['stack_height']
                          : sprintf('%.3Fpx', $fullCellHeightPx($rowspan));
                      @endphp
                      <td class="slot slot--filled" rowspan="{{ $rowspan }}">
                        <div class="slot-stack @if($isSingleEvent) slot-stack--single @endif" style="--slot-fill-height: {{ $fillHeight }};">
                          @foreach ($eventsInCell as $event)
                            @php
                              $kind = $event['kind'] ?? 'class';
                              $timeRange = $formatClock($event['starts_at']) . ' - ' . $formatClock($event['ends_at']);
                              $eventCode = trim((string)($event['course'] ?? $event['label'] ?? 'Scheduled Event'));
                              $eventTitle = trim((string)($event['title'] ?? ''));
                              $eventMeta = trim((string)($event['meta'] ?? ''));
                              $toneClass = 'event--tone-' . (abs(crc32($eventCode . $event['starts_at'] . $day)) % 8);
                              $styleParts = [];

                              if ($kind === 'class') {
                                if (!empty($event['style']['bg'])) {
                                    $styleParts[] = '--event-bg:' . $event['style']['bg'];
                                }
                                if (!empty($event['style']['border'])) {
                                    $styleParts[] = '--event-border:' . $event['style']['border'];
                                }
                              }

                              if ($isSingleEvent && $singleLayout) {
                                $styleParts[] = '--event-top:' . $singleLayout['top'];
                                $styleParts[] = '--event-height:' . $singleLayout['height'];
                              }

                              $inlineStyle = implode(';', $styleParts);
                            @endphp
                            <div class="event {{ $kind }} {{ $toneClass }} @if($isSingleEvent) event--single @endif" @if($inlineStyle !== '') style="{{ $inlineStyle }}" @endif>
                              <div class="event__label">{{ $eventCode }}</div>
                              @if($eventTitle !== '')
                                <div class="event__title">{{ $eventTitle }}</div>
                              @endif
                              @if($eventMeta !== '')
                                <div class="event__meta">{{ $eventMeta }}</div>
                              @endif
                              <small class="event__time">{{ $timeRange }}</small>
                            </div>
                          @endforeach
                        </div>
                      </td>
                    @else
                      <td class="slot"></td>
                    @endif
                  @endforeach
                </tr>
              @endfor
            </tbody>
          </table>
        </div>
      </div>
    @endif
  </div>

  @if($footerNote)
    <p class="schedule-grid-note muted">{{ $footerNote }}</p>
  @endif
</section>

@if($isPrint && $autoPrint)
  <script>
    (() => {
      const fitPrintableGrid = () => {
        const frame = document.querySelector('[data-print-frame]');
        const canvas = document.querySelector('[data-print-grid]');

        if (!frame || !canvas) {
          return;
        }

        canvas.style.transform = 'scale(1)';

        const naturalWidth = canvas.scrollWidth;
        const naturalHeight = canvas.scrollHeight;
        const availableWidth = frame.clientWidth;
        const availableHeight = frame.clientHeight;

        if (!naturalWidth || !naturalHeight || !availableWidth || !availableHeight) {
          return;
        }

        const scale = Math.min(1, availableWidth / naturalWidth, availableHeight / naturalHeight);
        canvas.style.transform = `scale(${scale})`;
      };

      window.addEventListener('resize', fitPrintableGrid);
      window.addEventListener('load', () => {
        requestAnimationFrame(() => {
          fitPrintableGrid();
          requestAnimationFrame(() => window.print());
        });
      });
    })();
  </script>
@endif
