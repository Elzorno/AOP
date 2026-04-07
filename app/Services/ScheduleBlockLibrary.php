<?php

namespace App\Services;

/**
 * Encodes the canonical semester schedule blocks from the institution's
 * "Semester Schedule Blocks – Lecture" and "Semester Schedule Blocks – Labs" PDFs.
 *
 * Each entry represents one valid meeting block:
 *   days_signature  – canonical sorted day string, e.g. "Fri,Mon,Wed"
 *   days            – array of day tokens
 *   starts_at       – HH:MM (24-hour)
 *   ends_at         – HH:MM (24-hour)
 *   type            – 'LECTURE' | 'LAB'
 *   credit_label    – human-readable hint, e.g. "3-credit MWF"
 *
 * Day tokens used throughout this app: Mon Tue Wed Thu Fri Sat Sun
 * Abbreviations in the PDF map as: M=Mon T=Tue W=Wed R=Thu F=Fri
 */
class ScheduleBlockLibrary
{
    // -----------------------------------------------------------------------
    // 2-day patterns that require a 2 pm or later start (per scheduling rules)
    // TR is excluded – it is the standard 2-day pattern for 80-min classes.
    // -----------------------------------------------------------------------
    public const TWO_DAY_AFTERNOON_PATTERNS = [
        'Mon,Wed',
        'Mon,Fri',
        'Wed,Fri',
        'Mon,Sat',
        'Tue,Sat',
        'Wed,Sat',
        'Thu,Sat',
        'Fri,Sat',
    ];

    // 1-day patterns require a 5 pm or later start.
    public const ONE_DAY_PATTERNS = [
        ['Mon'], ['Tue'], ['Wed'], ['Thu'], ['Fri'], ['Sat'], ['Sun'],
    ];

    // -----------------------------------------------------------------------
    // Canonical lecture blocks
    // -----------------------------------------------------------------------
    private static function lectureBlocks(): array
    {
        return [
            // ---- MWF  55-min  (3-credit standard) ----
            ['Mon','Wed','Fri', '08:00','08:55','3-credit MWF'],
            ['Mon','Wed','Fri', '09:05','10:00','3-credit MWF'],
            ['Mon','Wed','Fri', '10:10','11:05','3-credit MWF'],
            ['Mon','Wed','Fri', '11:15','12:10','3-credit MWF'],
            ['Mon','Wed','Fri', '12:20','13:15','3-credit MWF'],
            ['Mon','Wed','Fri', '13:25','14:20','3-credit MWF'],
            ['Mon','Wed','Fri', '14:30','15:25','3-credit MWF'],

            // ---- TR  80-min  (3-credit standard) ----
            ['Tue','Thu', '08:00','09:20','3-credit TR'],
            ['Tue','Thu', '09:30','10:50','3-credit TR'],
            ['Tue','Thu', '11:00','12:20','3-credit TR'],
            ['Tue','Thu', '12:30','13:50','3-credit TR'],
            ['Tue','Thu', '14:00','15:20','3-credit TR'],

            // ---- MTWR  55-min  (4-credit) ----
            ['Mon','Tue','Wed','Thu', '08:00','08:55','4-credit MTWR'],
            ['Mon','Tue','Wed','Thu', '09:05','10:00','4-credit MTWR'],
            ['Mon','Tue','Wed','Thu', '10:10','11:05','4-credit MTWR'],
            ['Mon','Tue','Wed','Thu', '11:15','12:10','4-credit MTWR'],
            ['Mon','Tue','Wed','Thu', '12:20','13:15','4-credit MTWR'],
            ['Mon','Tue','Wed','Thu', '13:25','14:20','4-credit MTWR'],
            ['Mon','Tue','Wed','Thu', '14:30','15:25','4-credit MTWR'],

            // ---- MWF  80-min  (4-credit alt) ----
            ['Mon','Wed','Fri', '08:00','09:20','4-credit MWF 80min'],
            ['Mon','Wed','Fri', '10:00','11:15','4-credit MWF 80min'],
            ['Mon','Wed','Fri', '12:00','13:15','4-credit MWF 80min'],

            // ---- TR  110-min  (4-credit TR) ----
            ['Tue','Thu', '08:00','09:50','4-credit TR 110min'],
            ['Tue','Thu', '10:00','11:50','4-credit TR 110min'],
            ['Tue','Thu', '12:00','13:50','4-credit TR 110min'],
            ['Tue','Thu', '14:00','15:50','4-credit TR 110min'],

            // ---- MTWRF  55-min  (5-credit standard) ----
            ['Mon','Tue','Wed','Thu','Fri', '08:00','08:55','5-credit MTWRF'],
            ['Mon','Tue','Wed','Thu','Fri', '09:05','10:00','5-credit MTWRF'],
            ['Mon','Tue','Wed','Thu','Fri', '10:10','11:05','5-credit MTWRF'],
            ['Mon','Tue','Wed','Thu','Fri', '11:15','12:10','5-credit MTWRF'],
            ['Mon','Tue','Wed','Thu','Fri', '12:20','13:15','5-credit MTWRF'],
            ['Mon','Tue','Wed','Thu','Fri', '13:25','14:20','5-credit MTWRF'],
            ['Mon','Tue','Wed','Thu','Fri', '14:30','15:25','5-credit MTWRF'],

            // ---- TR  (5-credit alt) ----
            ['Tue','Thu', '08:00','09:50','5-credit TR'],

            // ---- 3:00 afternoon  MTWR ----
            ['Mon','Tue','Wed','Thu', '15:35','16:30','afternoon MTWR 55min'],
            ['Mon','Tue','Wed','Thu', '15:00','16:05','afternoon MTWR 65min'],

            // ---- 3:30  MW  (2-day afternoon – valid per 2 pm rule) ----
            ['Mon','Wed', '15:30','16:50','MW 80min'],
            ['Mon','Wed', '15:30','17:20','MW 110min'],
            ['Mon','Wed', '15:30','17:40','MW 130min'],

            // ---- 3:30  TR  (2-day afternoon) ----
            ['Tue','Thu', '15:30','16:50','TR 80min afternoon'],
            ['Tue','Thu', '15:30','17:20','TR 110min afternoon'],
            ['Tue','Thu', '15:30','17:40','TR 130min afternoon'],

            // ---- 9:30  TR  (late-morning 80-min alt) ----
            ['Tue','Thu', '09:30','10:50','TR 80min 9:30'],

            // ---- Evening: single-day (≥ 5 pm) ----
            ['Mon', '17:00','19:35','eve Mon 155min'],
            ['Mon', '17:00','18:20','eve Mon MW 80min'],
            ['Mon', '17:00','20:20','eve Mon 200min'],
            ['Mon', '17:00','18:50','eve Mon MW 110min'],
            ['Mon', '17:00','21:10','eve Mon 250min'],
            ['Mon', '17:00','19:10','eve Mon MW 130min'],

            ['Tue', '17:00','19:35','eve Tue 155min'],
            ['Tue', '17:00','18:20','eve Tue TR 80min'],
            ['Tue', '17:00','20:20','eve Tue 200min'],
            ['Tue', '17:00','18:50','eve Tue TR 110min'],
            ['Tue', '17:00','21:10','eve Tue 250min'],
            ['Tue', '17:00','19:10','eve Tue TR 130min'],

            ['Wed', '17:00','19:35','eve Wed 155min'],
            ['Wed', '17:00','18:20','eve Wed MW 80min'],
            ['Wed', '17:00','20:20','eve Wed 200min'],
            ['Wed', '17:00','18:50','eve Wed MW 110min'],
            ['Wed', '17:00','21:10','eve Wed 250min'],
            ['Wed', '17:00','19:10','eve Wed MW 130min'],

            ['Thu', '17:00','19:35','eve Thu 155min'],
            ['Thu', '17:00','18:20','eve Thu TR 80min'],
            ['Thu', '17:00','20:20','eve Thu 200min'],
            ['Thu', '17:00','18:50','eve Thu TR 110min'],
            ['Thu', '17:00','21:10','eve Thu 250min'],
            ['Thu', '17:00','19:10','eve Thu TR 130min'],

            // ---- Evening: MW / TR two-day ----
            ['Mon','Wed', '17:00','18:20','eve MW 80min'],
            ['Mon','Wed', '17:00','18:50','eve MW 110min'],
            ['Mon','Wed', '17:00','19:10','eve MW 130min'],
            ['Tue','Thu', '17:00','18:20','eve TR 80min'],
            ['Tue','Thu', '17:00','18:50','eve TR 110min'],
            ['Tue','Thu', '17:00','19:10','eve TR 130min'],

            // ---- 6:30 evening ----
            ['Mon', '18:30','21:05','eve Mon 155min 6:30'],
            ['Mon', '18:30','19:50','eve Mon MW 80min 6:30'],
            ['Mon', '18:30','22:00','eve Mon 210min 6:30'],
            ['Mon', '18:30','20:20','eve Mon MW 110min 6:30'],
            ['Mon', '18:30','22:40','eve Mon 250min 6:30'],
            ['Mon', '18:30','20:35','eve Mon MW 125min 6:30'],

            ['Tue', '18:30','21:05','eve Tue 155min 6:30'],
            ['Tue', '18:30','19:45','eve Tue TR 75min 6:30'],
            ['Tue', '18:30','22:00','eve Tue 210min 6:30'],
            ['Tue', '18:30','20:20','eve Tue TR 110min 6:30'],
            ['Tue', '18:30','22:40','eve Tue 250min 6:30'],
            ['Tue', '18:30','20:35','eve Tue TR 125min 6:30'],

            ['Wed', '18:30','21:05','eve Wed 155min 6:30'],
            ['Wed', '18:30','19:50','eve Wed MW 80min 6:30'],
            ['Wed', '18:30','22:00','eve Wed 210min 6:30'],
            ['Wed', '18:30','20:20','eve Wed MW 110min 6:30'],
            ['Wed', '18:30','22:40','eve Wed 250min 6:30'],
            ['Wed', '18:30','20:35','eve Wed MW 125min 6:30'],

            ['Thu', '18:30','21:05','eve Thu 155min 6:30'],
            ['Thu', '18:30','19:45','eve Thu TR 75min 6:30'],
            ['Thu', '18:30','22:00','eve Thu 210min 6:30'],
            ['Thu', '18:30','20:20','eve Thu TR 110min 6:30'],
            ['Thu', '18:30','22:40','eve Thu 250min 6:30'],
            ['Thu', '18:30','20:35','eve Thu TR 125min 6:30'],

            ['Mon','Wed', '18:30','19:50','eve MW 80min 6:30'],
            ['Mon','Wed', '18:30','20:20','eve MW 110min 6:30'],
            ['Mon','Wed', '18:30','20:35','eve MW 125min 6:30'],
            ['Tue','Thu', '18:30','19:45','eve TR 75min 6:30'],
            ['Tue','Thu', '18:30','20:20','eve TR 110min 6:30'],
            ['Tue','Thu', '18:30','20:35','eve TR 125min 6:30'],

            // ---- 8:00 pm evening ----
            ['Mon', '20:00','22:35','eve Mon 8pm 155min'],
            ['Mon', '20:00','21:20','eve Mon MW 8pm 80min'],
            ['Mon', '20:00','21:50','eve Mon MW 8pm 110min'],
            ['Mon', '20:00','22:10','eve Mon MW 8pm 130min'],

            ['Tue', '20:00','22:35','eve Tue 8pm 155min'],
            ['Tue', '20:00','21:20','eve Tue TR 8pm 80min'],
            ['Tue', '20:00','21:50','eve Tue TR 8pm 110min'],
            ['Tue', '20:00','22:10','eve Tue TR 8pm 130min'],

            ['Wed', '20:00','22:35','eve Wed 8pm 155min'],
            ['Wed', '20:00','21:20','eve Wed MW 8pm 80min'],
            ['Wed', '20:00','21:50','eve Wed MW 8pm 110min'],
            ['Wed', '20:00','22:10','eve Wed MW 8pm 130min'],

            ['Thu', '20:00','22:35','eve Thu 8pm 155min'],
            ['Thu', '20:00','21:20','eve Thu TR 8pm 80min'],
            ['Thu', '20:00','21:50','eve Thu TR 8pm 110min'],
            ['Thu', '20:00','22:10','eve Thu TR 8pm 130min'],

            ['Mon','Wed', '20:00','21:20','eve MW 8pm 80min'],
            ['Mon','Wed', '20:00','21:50','eve MW 8pm 110min'],
            ['Mon','Wed', '20:00','22:10','eve MW 8pm 130min'],
            ['Tue','Thu', '20:00','21:20','eve TR 8pm 80min'],
            ['Tue','Thu', '20:00','21:50','eve TR 8pm 110min'],
            ['Tue','Thu', '20:00','22:10','eve TR 8pm 130min'],

            // ---- 9:30 pm two-day ----
            ['Mon','Wed', '21:30','22:50','eve MW 9:30 80min'],
            ['Tue','Thu', '21:30','22:50','eve TR 9:30 80min'],
        ];
    }

    // -----------------------------------------------------------------------
    // Canonical lab blocks (single-day, once per week)
    // -----------------------------------------------------------------------
    private static function labBlocks(): array
    {
        $days = ['Mon','Tue','Wed','Thu','Fri'];
        $blocks = [];

        // Durations: 100 min (1hr lab w/ hw), 150 min (1hr lab), 200 min (2hr lab w/ hw),
        //            300 min (2hr lab), 450 min (3hr lab)
        $slots = [
            ['08:00', 100], ['08:00', 150], ['08:00', 200], ['08:00', 300],
            ['09:05', 100], ['09:05', 150], ['09:05', 200],
            ['10:00', 100], ['10:00', 150], ['10:00', 200], ['10:00', 300],
            ['10:10', 100], ['10:10', 150], ['10:10', 200],
            ['11:00', 100], ['11:00', 150], ['11:00', 200],
            ['11:15', 100], ['11:15', 150],
            ['12:00', 100], ['12:00', 150], ['12:00', 200],
            ['12:20', 100], ['12:20', 150],
            ['13:25', 100], ['13:25', 150], ['13:25', 200], ['13:25', 300],
            ['14:00', 100], ['14:00', 150], ['14:00', 200],
            ['15:00', 100], ['15:00', 150], ['15:00', 200],
            ['15:30', 100], ['15:30', 150], ['15:30', 200], ['15:30', 300],
            ['17:00', 100], ['17:00', 150], ['17:00', 200], ['17:00', 300],
            ['18:30', 100], ['18:30', 150], ['18:30', 200], ['18:30', 300],
            ['20:00', 100], ['20:00', 150],
        ];

        // TR also has lab blocks at 9:30 start
        $trSlots = [
            ['09:30', 100], ['09:30', 150], ['09:30', 200], ['09:30', 295], ['09:30', 295],
            ['12:30', 100], ['12:30', 150], ['12:30', 200], ['12:30', 235], ['12:30', 295],
        ];

        foreach ($days as $day) {
            foreach ($slots as [$start, $dur]) {
                $end = self::addMinutes($start, $dur);
                $blocks[] = [
                    $day, $start, $end,
                    "lab {$dur}min {$day}",
                ];
            }
        }

        // TR-only 9:30 and 12:30 lab slots
        foreach (['Tue','Thu'] as $day) {
            foreach ($trSlots as [$start, $dur]) {
                $end = self::addMinutes($start, $dur);
                $blocks[] = [$day, $start, $end, "lab {$dur}min {$day} TR-anchor"];
            }
        }

        return $blocks;
    }

    // -----------------------------------------------------------------------
    // Build the full catalog on first access (lazy, cached in static)
    // -----------------------------------------------------------------------
    private static ?array $catalog = null;

    public static function all(): array
    {
        if (self::$catalog !== null) {
            return self::$catalog;
        }

        $entries = [];

        foreach (self::lectureBlocks() as $row) {
            // row: [...days, starts_at, ends_at, credit_label]
            $days = array_slice($row, 0, count($row) - 3);
            $starts = $row[count($row) - 3];
            $ends   = $row[count($row) - 2];
            $label  = $row[count($row) - 1];

            $sorted = $days;
            sort($sorted);

            $entries[] = [
                'type'          => 'LECTURE',
                'days'          => $days,
                'days_signature'=> implode(',', $sorted),
                'starts_at'     => $starts,
                'ends_at'       => $ends,
                'credit_label'  => $label,
            ];
        }

        foreach (self::labBlocks() as $row) {
            [$day, $starts, $ends, $label] = $row;
            $entries[] = [
                'type'          => 'LAB',
                'days'          => [$day],
                'days_signature'=> $day,
                'starts_at'     => $starts,
                'ends_at'       => $ends,
                'credit_label'  => $label,
            ];
        }

        self::$catalog = $entries;
        return $entries;
    }

    // -----------------------------------------------------------------------
    // Public query API
    // -----------------------------------------------------------------------

    /**
     * Returns true when (days, starts_at, ends_at, type) exactly matches
     * a canonical block in the library.
     */
    public static function isCanonicalBlock(array $days, string $startsAt, string $endsAt, string $type): bool
    {
        $sig = self::makeSig($days);
        $s   = substr($startsAt, 0, 5);
        $e   = substr($endsAt, 0, 5);

        foreach (self::all() as $entry) {
            if (
                $entry['type'] === $type &&
                $entry['days_signature'] === $sig &&
                $entry['starts_at'] === $s &&
                $entry['ends_at'] === $e
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns all canonical start times for a given days signature + type.
     * Useful for populating a start-time picker.
     */
    public static function canonicalStartsFor(array $days, string $type): array
    {
        $sig = self::makeSig($days);

        $starts = [];
        foreach (self::all() as $entry) {
            if ($entry['type'] === $type && $entry['days_signature'] === $sig) {
                $starts[] = $entry['starts_at'];
            }
        }

        return array_values(array_unique($starts));
    }

    /**
     * Returns canonical blocks suited for the given credit/hour profile.
     *
     * @param  int    $creditHours          e.g. 3
     * @param  float  $lectureHrsPerWeek    from catalog course
     * @param  float  $labHrsPerWeek        from catalog course
     * @param  string $blockType            'LECTURE' | 'LAB'
     * @return array  subset of self::all() entries
     */
    public static function suggestionsFor(
        int $creditHours,
        float $lectureHrsPerWeek,
        float $labHrsPerWeek,
        string $blockType
    ): array {
        if ($blockType === 'LAB') {
            return self::labSuggestionsFor($labHrsPerWeek);
        }

        return self::lectureSuggestionsFor($creditHours, $lectureHrsPerWeek);
    }

    /**
     * Returns all canonical block entries that match a days signature,
     * ordered chronologically.
     */
    public static function blocksForDaysPattern(array $days, string $type): array
    {
        $sig = self::makeSig($days);

        $matches = array_filter(
            self::all(),
            fn ($e) => $e['type'] === $type && $e['days_signature'] === $sig
        );

        usort($matches, fn ($a, $b) => strcmp($a['starts_at'], $b['starts_at']));

        return array_values($matches);
    }

    /**
     * Returns the canonical end time for a given (days, starts_at, type).
     * Returns null when no canonical match exists.
     */
    public static function canonicalEndFor(array $days, string $startsAt, string $type): ?string
    {
        $sig = self::makeSig($days);
        $s   = substr($startsAt, 0, 5);

        foreach (self::all() as $entry) {
            if (
                $entry['type'] === $type &&
                $entry['days_signature'] === $sig &&
                $entry['starts_at'] === $s
            ) {
                return $entry['ends_at'];
            }
        }

        return null;
    }

    // -----------------------------------------------------------------------
    // Suggestion helpers
    // -----------------------------------------------------------------------

    private static function lectureSuggestionsFor(int $creditHours, float $lectureHrsPerWeek): array
    {
        // Determine candidate day-pattern signatures based on credit hours
        $candidateSigs = match (true) {
            $creditHours <= 1 => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],           // 1-credit: single day (eve)
            $creditHours === 2 => ['Mon,Wed', 'Tue,Thu', 'Mon,Fri', 'Wed,Fri'], // 2-credit: 2-day
            $creditHours === 3 => ['Fri,Mon,Wed', 'Thu,Tue'],                    // 3-credit: MWF or TR
            $creditHours === 4 => ['Mon,Thu,Tue,Wed', 'Fri,Mon,Wed'],            // 4-credit: MTWR or MWF
            $creditHours >= 5 => ['Fri,Mon,Thu,Tue,Wed'],                        // 5-credit: MTWRF
            default           => ['Fri,Mon,Wed', 'Thu,Tue'],
        };

        $results = [];
        foreach (self::all() as $entry) {
            if ($entry['type'] !== 'LECTURE') continue;
            if (in_array($entry['days_signature'], $candidateSigs, true)) {
                $results[] = $entry;
            }
        }

        usort($results, fn ($a, $b) => strcmp($a['starts_at'], $b['starts_at']));

        return $results;
    }

    private static function labSuggestionsFor(float $labHrsPerWeek): array
    {
        // Determine expected duration range from lab credit hours
        // 1 lab credit (no hw) = 150 min; (with hw) = 100 min
        // 2 lab credits (no hw) = 300 min; (with hw) = 200 min
        // 3 lab credits = 450 min
        $targetMin = (int) round($labHrsPerWeek * 50);  // 50 min per contact hour
        $tolerance = 30;

        $results = [];
        foreach (self::all() as $entry) {
            if ($entry['type'] !== 'LAB') continue;

            $dur = self::durationMinutes($entry['starts_at'], $entry['ends_at']);
            if (abs($dur - $targetMin) <= $tolerance) {
                $results[] = $entry;
            }
        }

        usort($results, fn ($a, $b) => strcmp($a['starts_at'], $b['starts_at']));

        return $results;
    }

    // -----------------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------------

    public static function makeSig(array $days): string
    {
        $sorted = $days;
        sort($sorted);
        return implode(',', $sorted);
    }

    public static function durationMinutes(string $start, string $end): int
    {
        return self::toMinutes($end) - self::toMinutes($start);
    }

    public static function toMinutes(string $hhmm): int
    {
        $hhmm = substr($hhmm, 0, 5);
        [$h, $m] = array_map('intval', explode(':', $hhmm));
        return ($h * 60) + $m;
    }

    private static function addMinutes(string $hhmm, int $minutes): string
    {
        $total = self::toMinutes($hhmm) + $minutes;
        return sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
    }
}
