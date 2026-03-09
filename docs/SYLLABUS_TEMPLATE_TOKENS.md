# Syllabus Template Tokens

AOP populates uploaded DOCX templates by replacing `{{TOKEN_NAME}}` placeholders.

## Core syllabus tokens

Typical always-available placeholders include:

- `{{UNIVERSITY_NAME}}`
- `{{COURSE_CODE}}`
- `{{COURSE_TITLE}}`
- `{{TERM_CODE}}`
- `{{TERM_NAME}}`
- `{{TERM_LINE}}`
- `{{SECTION_CODE}}`
- `{{INSTRUCTOR_NAME}}`
- `{{INSTRUCTOR_EMAIL}}`
- `{{CREDIT_HOURS}}`
- `{{DELIVERY_MODE}}`
- `{{LOCATION}}`
- `{{MEETING_DAYS}}`
- `{{MEETING_TIME}}`
- `{{MEETING_LINES}}`
- `{{OFFICE_HOURS}}`
- `{{OFFICE_HOURS_LINES}}`
- `{{PREREQUISITES}}`
- `{{COREQUISITES}}`
- `{{COURSE_DESCRIPTION}}`
- `{{COURSE_OBJECTIVES}}`
- `{{REQUIRED_MATERIALS}}`
- `{{COURSE_NOTES}}`
- `{{SECTION_NOTES}}`
- `{{SYLLABUS_DATE}}`
- `{{DEPARTMENT_LINE}}`

## Structured section tokens

The syllabus structure builder now drives export placement in two ways.

### Aggregate structured-section output

Use these when the DOCX template should place all visible structured sections together in syllabus order:

- `{{STRUCTURED_SECTIONS}}`
- `{{STRUCTURED_SECTION_COUNT}}`
- `{{STRUCTURED_SECTION_SLUGS}}`
- `{{STRUCTURED_SECTION_TITLES}}`

### Indexed structured-section output

Use these when the DOCX template needs the first, second, third, etc. visible structured section in order:

- `{{STRUCTURED_SECTION_01_TITLE}}`
- `{{STRUCTURED_SECTION_01_SLUG}}`
- `{{STRUCTURED_SECTION_01_CONTENT}}`
- `{{STRUCTURED_SECTION_02_TITLE}}`
- `{{STRUCTURED_SECTION_02_SLUG}}`
- `{{STRUCTURED_SECTION_02_CONTENT}}`
- continue as needed

### Slug-based structured-section output

Every structured section definition also exposes slug-based placeholders so the template can intentionally place a specific section wherever desired.

Example for a definition with slug `attendance`:

- `{{SECTION_ATTENDANCE_TITLE}}`
- `{{SECTION_ATTENDANCE_CONTENT}}`
- `{{SECTION_ATTENDANCE_ENABLED}}`
- `{{SECTION_ATTENDANCE_ORDER}}`
- `{{SECTION_ATTENDANCE_SCOPE}}`

Example for a definition with slug `weekly-schedule`:

- `{{SECTION_WEEKLY_SCHEDULE_TITLE}}`
- `{{SECTION_WEEKLY_SCHEDULE_CONTENT}}`
- `{{SECTION_WEEKLY_SCHEDULE_ENABLED}}`
- `{{SECTION_WEEKLY_SCHEDULE_ORDER}}`
- `{{SECTION_WEEKLY_SCHEDULE_SCOPE}}`

If a structured section is disabled for a syllabus and is not required, its slug-based title/content placeholders resolve to blank text while `ENABLED` resolves to `0`.

## Legacy shared block tokens

Legacy shared blocks remain available during transition.

- `{{LEGACY_BLOCKS}}`
- `{{CUSTOM_BLOCKS}}` (backward-compatible combined output)
- `{{LEGACY_BLOCK_COUNT}}`
- `{{LEGACY_BLOCK_01_TITLE}}`
- `{{LEGACY_BLOCK_01_CATEGORY}}`
- `{{LEGACY_BLOCK_01_CONTENT}}`
- continue as needed

## Practical template strategy

Recommended approach for new DOCX templates:

1. Place the core course/instructor/meeting placeholders in the header table.
2. Use slug-based section tokens for sections that should always appear in a known place.
3. Use indexed section tokens only when the template wants the visible section order without naming each slug explicitly.
4. Keep `{{CUSTOM_BLOCKS}}` only for compatibility or transition-period templates.

## Important template note

Placeholders should be typed in Word as plain contiguous text inside a single run whenever possible, for example:

`{{COURSE_TITLE}}`

Do not intentionally split a placeholder across multiple styled fragments.
