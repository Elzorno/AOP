<?php

namespace App\Services;

use App\Models\OfficeHourBlock;
use App\Models\Section;
use App\Models\Syllabus;
use App\Models\SyllabusBlock;
use App\Models\SyllabusSectionDefinition;
use App\Models\Term;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SyllabusDataService
{
    public function buildPacketForSection(Section $section): array
    {
        $section->loadMissing([
            'offering.term',
            'offering.catalogCourse',
            'instructor',
            'meetingBlocks.room',
        ]);

        /** @var Term|null $term */
        $term = $section->offering?->term;

        $course = $section->offering?->catalogCourse;
        $instructor = $section->instructor;

        $officeHours = [];
        if ($term && $instructor) {
            $officeHours = OfficeHourBlock::query()
                ->where('term_id', $term->id)
                ->where('instructor_id', $instructor->id)
                ->orderBy('starts_at')
                ->get()
                ->map(fn ($b) => [
                    'days' => $b->days_json ?? [],
                    'start' => substr((string)$b->starts_at, 0, 5),
                    'end' => substr((string)$b->ends_at, 0, 5),
                    'notes' => $b->notes,
                ])
                ->all();
        }

        $meetingBlocks = $section->meetingBlocks
            ->sortBy('starts_at')
            ->map(fn ($mb) => [
                'type' => is_object($mb->type) && property_exists($mb->type, 'value') ? $mb->type->value : (string)$mb->type,
                'days' => $mb->days_json ?? [],
                'start' => substr((string)$mb->starts_at, 0, 5),
                'end' => substr((string)$mb->ends_at, 0, 5),
                'room' => $mb->room?->name ?? '',
                'notes' => $mb->notes,
            ])
            ->values()
            ->all();

        return [
            'generated_at' => now()->toIso8601String(),
            'term' => [
                'code' => $term?->code ?? '',
                'name' => $term?->name ?? '',
            ],
            'course' => [
                'code' => $course?->code ?? '',
                'title' => $course?->title ?? '',
                'department' => $course?->department ?? '',
                'objectives' => $course?->objectives ?? '',
                'required_materials' => $course?->required_materials ?? '',
                'credits_text' => $course?->credits_text ?? '',
                'credits_min' => $course?->credits_min,
                'credits_max' => $course?->credits_max,
                'contact_hours_per_week' => $course?->contact_hours_per_week,
                'course_lab_fee' => $course?->course_lab_fee,
                'prerequisites' => $course?->prereq_text ?? '',
                'corequisites' => $course?->coreq_text ?? '',
                'description' => $course?->description ?? '',
                'notes' => $course?->notes ?? '',
            ],
            'section' => [
                'code' => $section->section_code,
                'modality' => is_object($section->modality) && property_exists($section->modality, 'value')
                ? $section->modality->value
                : (string)$section->modality,
                'notes' => $section->notes,
            ],
            'instructor' => [
                'name' => $instructor?->name ?? '',
                'email' => $instructor?->email ?? '',
            ],
            'office_hours' => $officeHours,
            'meeting_blocks' => $meetingBlocks,
            'syllabus_sections' => $this->buildStructuredSections($section),
            'blocks' => $this->buildGlobalBlocks(),
        ];
    }

    public function formatOfficeHoursLine(array $officeHours): string
    {
        if (count($officeHours) === 0) {
            return 'TBD';
        }

        $chunks = [];
        foreach ($officeHours as $b) {
            $days = $this->daysToString($b['days'] ?? []);
            $start = $b['start'] ?? '';
            $end = $b['end'] ?? '';
            $label = trim($days . ' ' . $start . '-' . $end);
            if (!empty($b['notes'])) {
                $label .= ' (' . $b['notes'] . ')';
            }
            if ($label !== '') {
                $chunks[] = $label;
            }
        }

        return $chunks ? implode('; ', $chunks) : 'TBD';
    }

    public function formatMeetingInfo(array $meetingBlocks): array
    {
        if (count($meetingBlocks) === 0) {
            return [
                'days' => 'TBD',
                'time' => 'TBD',
                'location' => 'TBD',
                'delivery_mode' => 'TBD',
            ];
        }

        // Use the first block as the "primary" meeting info.
        $mb = $meetingBlocks[0];
        $days = $this->daysToString($mb['days'] ?? []);
        $time = trim(($mb['start'] ?? '') . '-' . ($mb['end'] ?? ''));
        $room = $mb['room'] ?? '';

        return [
            'days' => $days !== '' ? $days : 'TBD',
            'time' => $time !== '-' ? $time : 'TBD',
            'location' => $room !== '' ? $room : 'TBD',
            'delivery_mode' => 'TBD',
        ];
    }


    private function buildStructuredSections(Section $section): array
    {
        if (!Schema::hasTable('syllabus_section_definitions')) {
            return [];
        }

        $definitions = SyllabusSectionDefinition::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($definitions->count() === 0) {
            return [];
        }

        /** @var Syllabus|null $syllabus */
        $syllabus = $section->relationLoaded('syllabus')
            ? $section->syllabus
            : Syllabus::query()->with('sectionItems')->where('section_id', $section->id)->first();

        $items = collect(optional($syllabus)->sectionItems ?? [])->keyBy('syllabus_section_definition_id');

        return $definitions
            ->map(function (SyllabusSectionDefinition $definition) use ($items): array {
                $item = $items->get($definition->id);

                $title = trim((string) ($item?->title_override ?: $definition->title));
                $content = $definition->scope === 'global'
                    ? (string) ($definition->default_content ?? '')
                    : (string) (($item?->content_markdown !== null && trim((string) $item?->content_markdown) !== '')
                        ? $item?->content_markdown
                        : ($definition->default_content ?? ''));

                $content = $this->normalizeMarkdown($content);
                $isEnabled = $definition->is_required ? true : (bool) ($item?->is_enabled ?? true);

                return [
                    'id' => $definition->id,
                    'title' => $title !== '' ? $title : (string) $definition->title,
                    'slug' => (string) $definition->slug,
                    'category' => $definition->category ? (string) $definition->category : '',
                    'description' => $definition->description ? (string) $definition->description : '',
                    'scope' => (string) $definition->scope,
                    'content' => $content,
                    'content_rendered' => $this->renderMarkdownHtml($content),
                    'content_preview_text' => $content !== ''
                        ? $this->markdownToPreviewText($content, 180)
                        : 'No content entered yet.',
                    'is_required' => (bool) $definition->is_required,
                    'is_active' => (bool) $definition->is_active,
                    'is_enabled' => $isEnabled,
                    'is_locked' => (bool) $definition->is_locked,
                    'sort_order' => (int) ($item?->sort_order ?? $definition->sort_order ?? 0),
                    'can_edit_per_syllabus' => $definition->scope === 'syllabus',
                    'item_id' => $item?->id,
                ];
            })
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->values()
            ->all();
    }

    private function buildGlobalBlocks(): array
    {
        return SyllabusBlock::query()
            ->orderByRaw("CASE WHEN category IS NULL OR TRIM(category) = '' THEN 1 ELSE 0 END")
            ->orderBy('category')
            ->orderBy('id')
            ->get()
            ->map(function (SyllabusBlock $block): array {
                $markdown = $this->normalizeMarkdown((string) ($block->content_html ?? ''));

                return [
                    'id' => $block->id,
                    'title' => (string) $block->title,
                    'category' => $block->category ? (string) $block->category : '',
                    'content' => $markdown,
                    'content_rendered' => $this->renderMarkdownHtml($markdown),
                    'content_preview_text' => $this->markdownToPreviewText($markdown, 180),
                    'version' => $block->version ? (string) $block->version : '',
                    'is_locked' => (bool) $block->is_locked,
                ];
            })
            ->values()
            ->all();
    }

    private function normalizeMarkdown(string $content): string
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        return rtrim($content);
    }

    private function renderMarkdownHtml(string $markdown): string
    {
        if ($markdown === '') {
            return '<p>—</p>';
        }

        return Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    private function markdownToPreviewText(string $markdown, int $limit = 180): string
    {
        $rendered = strip_tags($this->renderMarkdownHtml($markdown));
        $rendered = preg_replace('/\s+/u', ' ', $rendered ?? '');
        $rendered = trim((string) $rendered);

        return $rendered !== '' ? Str::limit($rendered, $limit) : '—';
    }

    private function daysToString(array $days): string
    {
        $order = ['Mon'=>1,'Tue'=>2,'Wed'=>3,'Thu'=>4,'Fri'=>5,'Sat'=>6,'Sun'=>7];
        $days = array_values(array_filter($days, fn($d) => is_string($d) && $d !== ''));
        usort($days, fn($a,$b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));
        return implode(', ', $days);
    }
}
