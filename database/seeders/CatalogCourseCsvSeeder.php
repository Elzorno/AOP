<?php

namespace Database\Seeders;

use App\Models\CatalogCourse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class CatalogCourseCsvSeeder extends Seeder
{
    public function run(): void
    {
        $path = base_path('database/seed-data/catalog_courses_full.csv');

        if (!File::exists($path)) {
            throw new \RuntimeException("Seed CSV not found: {$path}");
        }

        $fh = fopen($path, 'r');
        if (!$fh) {
            throw new \RuntimeException("Unable to open CSV: {$path}");
        }

        $header = fgetcsv($fh);
        if (!$header) {
            fclose($fh);
            throw new \RuntimeException("CSV appears empty: {$path}");
        }

        $header = array_map(fn($h) => trim((string)$h), $header);

        while (($row = fgetcsv($fh)) !== false) {
            $assoc = [];
            foreach ($header as $i => $key) {
                $assoc[$key] = $row[$i] ?? null;
            }

            $code = trim((string)($assoc['code'] ?? ''));
            $title = trim((string)($assoc['title'] ?? ''));

            if ($code === '' || $title === '') {
                continue;
            }

            $creditsText = trim((string)($assoc['credits'] ?? ''));
            $creditsMin = $this->toFloatOrNull($assoc['credits_min'] ?? null);
            $creditsMax = $this->toFloatOrNull($assoc['credits_max'] ?? null);
            $creditsNumeric = $creditsMin ?? $this->toFloatOrNull($creditsText) ?? 0;

            $lecture = $this->toFloatOrNull($assoc['lecture_hours_per_week'] ?? null);
            $lab = $this->toFloatOrNull($assoc['lab_hours_per_week'] ?? null);
            $contact = $this->toFloatOrNull($assoc['contact_hours_per_week'] ?? null);

            $isActive = $this->toBool($assoc['is_active'] ?? 'true');

            CatalogCourse::updateOrCreate(
                ['code' => $code],
                [
                    'title' => $title,

                    'credits' => $creditsNumeric,
                    'credits_text' => ($creditsText === '' ? null : $creditsText),
                    'credits_min' => $creditsMin,
                    'credits_max' => $creditsMax,

                    'lecture_hours_per_week' => $lecture,
                    'lab_hours_per_week' => $lab,
                    'contact_hours_per_week' => $contact,

                    'course_lab_fee' => $this->nullIfBlank($assoc['course_lab_fee'] ?? null),

                    'prereq_text' => $this->nullIfBlank($assoc['prereq_text'] ?? null),
                    'coreq_text' => $this->nullIfBlank($assoc['coreq_text'] ?? null),
                    'notes' => $this->nullIfBlank($assoc['notes'] ?? null),
                    'description' => $this->nullIfBlank($assoc['description'] ?? null),

                    'is_active' => $isActive,
                ]
            );
        }

        fclose($fh);
    }

    private function nullIfBlank($v): ?string
    {
        $s = trim((string)($v ?? ''));
        return $s === '' ? null : $s;
    }

    private function toFloatOrNull($v): ?float
    {
        if ($v === null) return null;
        $s = trim((string)$v);
        if ($s === '' || strtolower($s) === 'nan') return null;
        if (!is_numeric($s)) return null;
        return (float)$s;
    }

    private function toBool($v): bool
    {
        $s = strtolower(trim((string)($v ?? 'true')));
        return !in_array($s, ['0','false','no','n','off'], true);
    }
}
