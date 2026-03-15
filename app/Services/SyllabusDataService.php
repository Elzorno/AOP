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
            'syllabus.sectionItems',
        ]);

        /** @var Term|null $term */
        $term = $section->offering?->term;

        $course = $section->offering?->catalogCourse;
        $instructor = $section->instructor;
        $syllabus = $section->syllabus;

        $courseDescription = $this->resolveCoreContent(
            $syllabus?->course_description_override,
            $course?->description
        );
        $courseObjectives = $this->resolveCoreContent(
            $syllabus?->course_objectives_override,
            $course?->objectives
        );
        $requiredMaterials = $this->resolveCoreContent(
            $syllabus?->required_materials_override,
            $course?->required_materials
        );

        $officeHours = [];
        if ($term && $instructor) {
            $officeHours = OfficeHourBlock::query()
                ->where('term_id', $term->id)
                ->where('instructor_id', $instructor->id)
                ->orderBy('starts_at')
                ->get()
                ->map(fn ($b) => [
                    'days' => $b->days_json ?? [],
                    'start' => substr((string) $b->starts_at, 0, 5),
                    'end' => substr((string) $b->ends_at, 0, 5),
                    'notes' => $b->notes,
                ])
                ->all();
        }

        $meetingBlocks = $section->meetingBlocks
            ->sortBy('starts_at')
            ->map(fn ($mb) => [
                'type' => is_object($mb->type) && property_exists($mb->type, 'value') ? $mb->type->value : (string) $mb->type,
                'days' => $mb->days_json ?? [],
                'start' => substr((string) $mb->starts_at, 0, 5),
                'end' => substr((string) $mb->ends_at, 0, 5),
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
                'objectives' => $courseObjectives['value'],
                'required_materials' => $requiredMaterials['value'],
                'credits_text' => $course?->credits_text ?? '',
                'credits_min' => $course?->credits_min,
                'credits_max' => $course?->credits_max,
                'contact_hours_per_week' => $course?->contact_hours_per_week,
                'course_lab_fee' => $course?->course_lab_fee,
                'prerequisites' => $course?->prereq_text ?? '',
                'corequisites' => $course?->coreq_text ?? '',
                'description' => $courseDescription['value'],
                'notes' => $course?->notes ?? '',
            ],
            'section' => [
                'code' => $section->section_code,
                'modality' => is_object($section->modality) && property_exists($section->modality, 'value')
                    ? $section->modality->value
                    : (string) $section->modality,
                'notes' => $section->notes,
            ],
            'instructor' => [
                'name' => $instructor?->name ?? '',
                'email' => $instructor?->email ?? '',
            ],
            'core_content' => [
                'course_description' => [
                    'key' => 'course_description',
                    'label' => 'Course Description',
                    'value' => $courseDescription['value'],
                    'source' => $courseDescription['source'],
                    'source_label' => $courseDescription['source_label'],
                    'has_override' => $courseDescription['has_override'],
                    'override_value' => $courseDescription['override_value'],
                    'catalog_value' => $courseDescription['catalog_value'],
                    'is_missing' => $courseDescription['source'] === 'missing',
                ],
                'course_objectives' => [
                    'key' => 'course_objectives',
                    'label' => 'Course Objectives',
                    'value' => $courseObjectives['value'],
                    'source' => $courseObjectives['source'],
                    'source_label' => $courseObjectives['source_label'],
                    'has_override' => $courseObjectives['has_override'],
                    'override_value' => $courseObjectives['override_value'],
                    'catalog_value' => $courseObjectives['catalog_value'],
                    'is_missing' => $courseObjectives['source'] === 'missing',
                ],
                'required_materials' => [
                    'key' => 'required_materials',
                    'label' => 'Required Materials',
                    'value' => $requiredMaterials['value'],
                    'source' => $requiredMaterials['source'],
                    'source_label' => $requiredMaterials['source_label'],
                    'has_override' => $requiredMaterials['has_override'],
                    'override_value' => $requiredMaterials['override_value'],
                    'catalog_value' => $requiredMaterials['catalog_value'],
                    'is_missing' => $requiredMaterials['source'] === 'missing',
                ],
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
                $titleOverride = trim((string) ($item?->title_override ?? ''));
                $contentOverride = $this->normalizeMarkdown((string) ($item?->content_markdown ?? ''));
                $defaultContent = $this->normalizeMarkdown((string) ($definition->default_content ?? ''));

                $title = $titleOverride !== '' ? $titleOverride : (string) $definition->title;
                $content = $definition->scope === 'global'
                    ? $defaultContent
                    : ($contentOverride !== '' ? $contentOverride : $defaultContent);

                $isEnabled = $definition->is_required ? true : (bool) ($item?->is_enabled ?? true);
                $sortOrder = (int) ($item?->sort_order ?? $definition->sort_order ?? 0);
                $hasPerSyllabusCustomization = $definition->scope === 'syllabus' && (
                    $titleOverride !== ''
                    || $contentOverride !== ''
                    || ($item !== null && !$definition->is_required && (bool) ($item->is_enabled ?? true) !== true)
                    || ($item !== null && $sortOrder !== (int) ($definition->sort_order ?? 0))
                );

                $source = $definition->scope === 'global'
                    ? 'global'
                    : ($hasPerSyllabusCustomization ? 'syllabus_override' : 'definition_default');

                $sourceLabel = match ($source) {
                    'global' => 'Global Shared',
                    'syllabus_override' => 'Per-Syllabus Override',
                    default => 'Shared Starter / Default',
                };

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
                    'sort_order' => $sortOrder,
                    'can_edit_per_syllabus' => $definition->scope === 'syllabus',
                    'item_id' => $item?->id,
                    'has_title_override' => $titleOverride !== '',
                    'has_content_override' => $contentOverride !== '',
                    'customized_for_syllabus' => $hasPerSyllabusCustomization,
                    'source' => $source,
                    'source_label' => $sourceLabel,
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

    private function resolveCoreContent(?string $overrideValue, ?string $catalogValue): array
    {
        $overrideValue = $this->normalizeTextBlock((string) ($overrideValue ?? ''));
        $catalogValue = $this->normalizeTextBlock((string) ($catalogValue ?? ''));

        if ($overrideValue !== '') {
            return [
                'value' => $overrideValue,
                'source' => 'override',
                'source_label' => 'Per-Syllabus Override',
                'has_override' => true,
                'override_value' => $overrideValue,
                'catalog_value' => $catalogValue,
            ];
        }

        if ($catalogValue !== '') {
            return [
                'value' => $catalogValue,
                'source' => 'catalog',
                'source_label' => 'Catalog Default',
                'has_override' => false,
                'override_value' => '',
                'catalog_value' => $catalogValue,
            ];
        }

        return [
            'value' => '',
            'source' => 'missing',
            'source_label' => 'Missing',
            'has_override' => false,
            'override_value' => '',
            'catalog_value' => '',
        ];
    }

    private function normalizeMarkdown(string $content): string
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        return rtrim($content);
    }

    private function normalizeTextBlock(string $content): string
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $content = preg_replace("/\n{3,}/", "\n\n", $content) ?? $content;

        return trim($content);
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
        $map = [
            'M' => 'Mon',
            'T' => 'Tue',
            'W' => 'Wed',
            'R' => 'Thu',
            'F' => 'Fri',
            'S' => 'Sat',
            'U' => 'Sun',
        ];

        $parts = [];
        foreach ($days as $d) {
            $parts[] = $map[$d] ?? $d;
        }

        return implode('/', $parts);
    }
}
