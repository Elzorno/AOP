# AOP Phase Log

This file tracks what each phase changed so the project remains understandable when switching chats or developers.

## Phase 13 (baseline)
- Schedule + Syllabi features existed; readiness and term lock controllers introduced.

## Phase 14
- Expanded Catalog Course fields needed for syllabi content.
- Improved syllabus mapping so prereqs/coreqs/department/etc populate correctly.
- Fixed Catalog route model binding mismatch.

## Phase 15
- Added ODHE instructional minutes pass/fail check to Schedule Readiness.
- Updated Readiness UI to show minutes required vs scheduled, with delta and quick edit links.
- Decluttered Schedule home UI (dashboard-first actions; moved secondary links into tiles).
- Added `docs/` source-of-truth documents for continuity.

## Phase 16
- Enforced term-level `buffer_minutes` in room, instructor, and office-hour conflict detection.
- Updated Readiness conflict output to explicitly show buffer minutes applied.

## Phase 17
- Added full-time instructor office hours compliance check (≥4 hours/week across ≥3 days) to Schedule Readiness.

## Phase 18
- Added syllabus render history (DOCX/PDF/HTML/JSON) stored in `syllabus_renders`.
- Implemented retention pruning: keep at most 2 successful DOCX and 2 successful PDF per section per term.
- Displayed latest successful DOCX/PDF timestamps on the Syllabi list and full render history on the section preview page.

## Phase 19
- Added “Clone Term Schedule” workflow so an existing term can be copied into a clean new term as a starting point.
- Clones offerings, sections, and meeting blocks, with instructor assignments optional and off by default.
- Prevents cloning into non-empty targets and ensures the new term starts locked/unpublished = false.

## Phase 20
- Security hardening and bug-fix pass based on full-app audit.
- Disabled self-registration by default behind `AOP_ALLOW_SELF_REGISTRATION=false`.
- Added missing public published-schedule routes and hardened public token comparison.
- Enforced active-term access for syllabi routes.
- Enforced server-side schedule lock on offering/section/meeting-block mutations and required locked schedule before publish.
- Tightened validation for section modality, meeting block type, and day tokens.
- Fixed Readiness and lock-warning conflict calculations so they honor `buffer_minutes`.

## Phase 21
- Color-coded schedule grids so class blocks use each instructor's profile color.
- Applied instructor profile colors to both instructor and room grid views.
- Hardened instructor color handling by normalizing/sanitizing hex values before display and storage.

## Phase 21.1
- Updated instructor and room grids so scheduled blocks expand to fill the full rowspan height.
- Preserved instructor color-coding while removing the visual gap inside multi-slot time blocks.
- Improved stacked event rendering so overlapping same-start blocks share the available cell height cleanly.

## Phase 21.2
- Fixed grid rowspans so end times that land between slot boundaries reserve the full visual span instead of truncating early.
- Adjusted single-event block rendering to use exact duration-based heights inside the reserved rowspan area.
- This makes a 09:30–11:50 block visually end near 11:50 instead of appearing to stop around 10:50 or 11:30.

## Phase 21.3
- Fixed grid rendering so off-slot start/end times position correctly within the reserved block height.
- Corrected buffer handling so a class ending at 11:50 does not conflict with one starting at 12:00 when the term buffer is 10 minutes.

## Phase 22
- Final release polish pass.
- Defaulted `AOP_VERSION` to `1.0.0` and redirected the root URL to login/dashboard instead of the default Laravel welcome page.
- Added response security headers middleware and enabled SQLite foreign keys at runtime.
- Unified the Profile page into the AOP layout.
- Added internal/public `noindex,nofollow` meta tags, AOP-specific README, `.env.example`, and deploy checklist docs.

## Phase 23
- Added `php artisan aop:reset-schedule-data` for clearing terms and scheduling data while preserving the course catalog.
- Reset also clears offerings, sections, meeting blocks, office hours, instructor term locks, syllabi, render history, and publication records.
- Generated schedule/syllabi artifacts are removed from storage during reset, but syllabus templates are preserved.

## Phase 24
- Added a shared Syllabi Blocks editor under the Syllabi area.
- Implemented create/edit/delete management for syllabus blocks using the existing `syllabus_blocks` table.
- Included shared blocks in each syllabus JSON packet and in the HTML preview so block content can be verified before DOCX/PDF formatting refinements.
- Added a `CUSTOM_BLOCKS` replacement value so a future DOCX template pass can place shared block content intentionally.

## Phase 24.1
- Fixed the Syllabi index crash caused by an undefined render-history variable.

## Phase 24.2
- Fixed mangled Blade rendering on the Syllabi index where raw template code was appearing in the browser.

## Phase 25
- Replaced the plain textarea block editor with the CDN-based Toast UI Markdown editor.
- Kept Markdown storage in the existing `content_html` field for schema compatibility.
- Preserved a textarea fallback if the CDN editor fails to load.
- Confirmed Markdown block previews render cleanly in the Syllabi list and section preview.

## Phase 26
- Upgraded the browser syllabus preview to a more document-style layout with a structured header table and cleaner section formatting.
- Added richer replacement tokens and better multi-line handling so DOCX/PDF exports preserve line breaks more reliably.
- Improved `CUSTOM_BLOCKS` plain-text formatting for DOCX template placement by converting Markdown into structured readable text.
- Updated deployment docs to reflect the current DOCX-template plus LibreOffice-based export pipeline.


## Phase 27
- Added a syllabus structure builder with ordered section definitions stored separately from legacy shared blocks.
- Each structure section can now be marked as global (same content for every syllabus) or per-syllabus (starter content plus per-section overrides).
- Added required/active/protected flags and explicit sort ordering for syllabus sections.
- Added per-syllabus editing from the syllabus preview page for sections whose content is allowed to vary by section.
- Updated the browser preview and JSON packet to render the structured syllabus sections before legacy shared blocks.
- Added `STRUCTURED_SECTIONS` and `LEGACY_BLOCKS` replacement values while keeping `CUSTOM_BLOCKS` compatible for DOCX/PDF template work.


## Phase 28
- Aligned DOCX/PDF export replacements more closely with the structured syllabus builder.
- Added slug-based structured section placeholders such as `SECTION_ATTENDANCE_TITLE` and `SECTION_ATTENDANCE_CONTENT`.
- Added indexed structured section placeholders such as `STRUCTURED_SECTION_01_TITLE` and `STRUCTURED_SECTION_01_CONTENT` for templates that follow visible syllabus order.
- Added indexed legacy-block placeholders to support transition-period template placement without losing the old block system.
- Added in-app template token reference guidance on the syllabus preview page and documented the token model in `docs/SYLLABUS_TEMPLATE_TOKENS.md`.

## Phase 29
- Added configurable syllabus export engine support through `AOP_SYLLABUS_EXPORT_ENGINE` with `auto`, `html`, and `template` modes.
- In `auto` mode, DOCX/PDF export now prefers the cleaner HTML syllabus layout first so exported files can track the in-app preview more closely.
- Kept the uploaded DOCX template path as a compatibility fallback instead of removing the existing token-based workflow.
- Updated syllabus preview/header output with an explicit university line and more export-friendly print styling for HTML-driven renderers.
- Updated deployment docs and syllabi UI messaging to explain the new export behavior.

## Phase 31
- Added a dedicated Core Syllabus Content editor for Course Description, Course Objectives, and Required Materials.
- Added per-syllabus override storage for those fixed top syllabus fields, with catalog-default fallback when override text is blank.
- Updated syllabus preview authoring UX so editable areas now show clearer source labeling such as Catalog Default, Per-Syllabus Override, Global Shared, and Missing.
- Added reset actions for per-syllabus structured sections so they can be returned to their shared starter content from the syllabus preview page.
- Added clearer syllabus authoring guidance and direct Core Content edit links from both the Syllabi index and the individual syllabus preview page.

## Phase 32
- Implemented **Draft Mode for Terms** adding a `status` column to the `terms` table and a robust `cloneToDraft()` method in the `Term` model.
- Integrated **Interactive Calendar View** using `FullCalendar.js` with drag-and-drop and resize functionality to update meeting block times in real-time.
- Created **ScheduleSuggestionService** and integrated a "Suggest Slot" button for sections to automatically find conflict-free scheduling windows.
- Launched the **Instructor Portal** allowing non-admin instructors to manage their own teaching preferences and office hours, and preview assigned classes/syllabi.
- Consolidated **DashboardController** to dynamically toggle between Admin and Instructor views based on user role and email matching.

## Phase 33
- Added a shared `MeetingBlockMutationService` so meeting block updates use one validation/mutation path for both section form edits and calendar drag/resize updates.
- Enforced update-time rules consistently across both paths: active term ownership, schedule lock guard, end-after-start validation, modality/room requirements, and room/instructor/office-hour conflict checks with `buffer_minutes`.
- Updated calendar update responses to return readable JSON 422 validation payloads and improved the calendar UI so failed drag/resize operations are reverted with visible inline status messaging.
- Upgraded Schedule Home to a workflow dashboard (`Build`, `Validate`, `Publish`) with active-term summary counts for offerings, sections, missing data, conflict totals, office-hours failures, publication version, term status, and lock state.
- Added GET-based server-side filtering and sorting to the sections index (`q`, `modality`, `instructor_id`, `missing`, `sort`) while keeping Blade server-rendered.
- Refactored section edit UX into clearer workspace cards and moved room data loading out of Blade into the controller (no model queries in touched Blade pages).
- Added focused AOP feature tests covering calendar safety rules and a sections missing-state filter.

## Phase 34
- Added a publish-time readiness summary on the Publish page showing key blockers: missing instructors, missing meeting blocks, missing rooms, room/instructor conflicts, office-hours failures, and instructional-minutes failures.
- Added a publish safety gate so snapshot publishing requires explicit confirmation when blockers are present, while still allowing intentional override snapshots when needed.
- Kept the existing lock requirement (`schedule_locked`) as a hard guard before any snapshot can be published.
- Added feature tests covering the new publish confirmation gate for both blocked and allowed paths.

## Phase 34.1
- Performed a UI continuity polish sweep across Terms, Schedule, Readiness, Publish, Catalog, Rooms, Sections, Instructor Dashboard, and Syllabi views.
- Standardized action controls to shared button variants (`btn`, `secondary`, `success`, `sm`) to remove mixed filled/text-only action styling.
- Standardized status semantics with shared badge variants (`success`, `warn`, `danger`, `info`, `muted`) and replaced scattered inline badge colors.
- Standardized success/error panel accents via shared panel classes (`panel-success`, `panel-danger`, `panel-warn`) for consistent alert/readability treatment.

## Phase 34.2
- Added a shared schedule-grid renderer so room and instructor grids use the same layout and print logic.
- Added dedicated print pages for room and instructor grids with a Letter-sized landscape layout and one-page fit scaling.
- Printable grids now hide unused days when possible so typical weekday schedules stay readable on paper.
- Added focused feature coverage for both room and instructor print routes.

## Phase 35
- Added `STAFF` as an intentional section instructor assignment and carried that label through readiness, grids, and exports.
- Made meeting blocks optional for online sections across readiness, filtering, and schedule handoff.
- Replaced the lock-and-snapshot publish workflow with a direct registrar-ready CSV export using a Build / Validate / Export workflow.
- Added registrar columns for course-section, title, faculty, credits, enrollment/capacity, days and times, term dates, campus, building, room, and a combined schedule cell.
- Preserved historical snapshot downloads while retiring schedule-lock enforcement for current editing and export.
- Added feature coverage for registrar formatting, STAFF display, asynchronous online sections, and legacy lock compatibility.

## Preservation and modernization plan — 2026-11-14
- Captured the current project before planning changes, including all 84 existing changed/untracked Git entries, installed dependencies, assets, runtime configuration, storage and Git metadata.
- Created an online SQLite backup and selected server-configuration/security-report archives at `/root/aop-backups/2026-11-14T150352Z`.
- Verified 14,320 archived regular files through isolated extraction. SQLite integrity passed, foreign-key checks returned zero violations, and all 26 table counts matched the restored snapshot.
- Added `AOP_MODERNIZATION_PLAN.md` covering isolated baseline verification, security remediation, explicit term/account boundaries, shared mutations, authoritative events, versioned policy, workspace improvements and repeatable recovery/releases.
- No production code, package, schema, service or application behavior changes were made. Existing source changes were not committed or overwritten. Full application boot/recovery testing remains a planned step; this checkpoint verifies files and database restoration.

## Modernization security and identity releases — deployed 2026-11-14
- Deployed security release `7e1993e`, then identity release `dc60a9d`, through atomic release directories while preserving rollback releases and verified database/source checkpoints.
- Activated canonical HTTPS through `syllabi.elzorno.com`, restricted proxy trust, Secure cookies and HSTS; hardened application, environment and SQLite permissions.
- Updated audited dependencies, bundled FullCalendar, protected current and historical CSV downloads from spreadsheet formulas, and disabled PHP FPM upload progress on patched PHP 8.3.6-0ubuntu0.24.04.13.
- Replaced mutable-email instructor ownership with a nullable unique `users.instructor_id` foreign key and admin Account Links workflow.

## Per-user term selection foundation — development checkpoint 2026-11-14
- Added a nullable `users.selected_term_id` foreign key with deletion-safe nulling and a `User::selectedTerm` relationship.
- Added an admin-only selection action so two administrators can retain independent schedule targets without changing the global active term.
- Kept scheduling on active-term behavior until every displayed route and write target can be converted together; no mixed-context workspace is exposed.
- Verified 74 tests / 266 assertions and rehearsed the migration on a disposable production database copy with successful integrity and foreign-key checks.
- This checkpoint is committed only in the isolated modernization workspace and is not deployed.

## Explicit offering term boundary — development checkpoint 2026-11-14
- Moved offering list, create, store and delete routes under `/aop/terms/{term}/schedule/offerings`.
- Offering writes now derive `term_id` from the route; cross-term offering deletion returns 404 even when another term is globally active.
- The legacy offering GET redirects to the authenticated administrator's selected term, falling back to the active term only when no selection exists.
- Non-active offering pages do not link into section and schedule-studio writes that still use the global active term.
- Verified the full suite at 77 tests / 274 assertions.

## Explicit offering term boundary `fa7dfb6` — deployed 2026-11-14
- Captured and verified `/root/aop-backups/2026-11-14T154720Z-pre-term-scope`, including the production database, environment and prior `dc60a9d` release.
- Ran the additive selected-term migration as batch 25 and atomically selected `/var/www/aop-releases/fa7dfb6`.
- Verified production database integrity and foreign keys, deployed route hashes, current compiled assets, HTTPS login, protected hidden files, service health and read-only application code.
- Release `dc60a9d` remains available for code rollback; its schema is compatible with the additive nullable column.

## Admin dashboard hotfix `2560003` — deployed 2026-11-14
- Fixed a production 500 on the authenticated admin dashboard caused by a missing `App\Models\Instructor` import in `DashboardController`.
- Added an admin-dashboard regression request and verified the full suite at 78 tests / 276 assertions.
- Deployed the single controller fix as release `2560003`, reloaded PHP-FPM, and directly exercised the production controller to confirm it returns the `aop.dashboard` view.
- Public root/login, SQLite integrity, foreign keys and service health remain good.

## Explicit section and meeting-block term boundary — development checkpoint 2026-11-14
- Moved section CRUD, filtering, bulk assignment and suggestion routes under `/aop/terms/{term}/schedule/sections`.
- Nested meeting-block create, update and delete routes now carry the same explicit term and verify both section-term and block-section ownership.
- Mixed-term bulk assignment fails atomically instead of silently updating only the matching subset.
- Updated all section and meeting-block form/link targets, including schedule studio and HTMX partials, to carry the route term.
- Verified non-active-term directory/create/edit rendering, route-term writes and cross-term rejection; the suite passes 82 tests / 287 assertions.

## Explicit section and meeting-block release `811483a` — deployed 2026-11-14
- Atomically deployed the verified section/meeting-block route slice without a schema migration; release `2560003` remains available for rollback.
- Rebuilt production caches, reloaded PHP-FPM, and verified the cached explicit routes, real production section-directory rendering, SQLite integrity, HTTPS login/assets and service health.

## Explicit open-lab term boundary — development checkpoint 2026-11-15
- Moved open-lab list/create/edit and all mutations under `/aop/terms/{term}/schedule/open-labs`.
- Open-lab writes derive their term from the URL, while update/delete verify the resource belongs to that term before mutation.
- Updated readiness, calendar, reports, schedule studio, event links and navigation; the compatibility GET redirects to the user's selected term.
- Verified non-active-term rendering and writes plus cross-term rejection; the full suite passes 86 tests / 303 assertions.

## Explicit open-lab release `d556c0f` — deployed 2026-11-15
- Atomically deployed the open-lab route slice without a schema migration; release `811483a` remains available for rollback.
- Rebuilt production caches, reloaded PHP-FPM, and verified the cached explicit routes, real production open-lab rendering, SQLite integrity, HTTPS login and service health.

## Explicit scheduled-event term boundary — development checkpoint 2026-11-15
- Moved scheduled-event list/create/store under `/aop/terms/{term}/schedule/events` and updated navigation/forms.
- Class-event creation rejects a section from another term before creating a meeting block; open-lab event sources use the route term.
- Verified non-active-term pages and writes plus selected-term compatibility redirects; the full suite passes 89 tests / 314 assertions.

## Explicit scheduled-event release `8809a40` — deployed 2026-11-15
- Atomically deployed the scheduled-event route slice without a schema migration; release `d556c0f` remains available for rollback.
- Rebuilt production caches, reloaded PHP-FPM, and verified cached routes, real production event-index rendering, SQLite integrity, HTTPS login and service health.

## Explicit calendar term boundary — development checkpoint 2026-11-15
- Moved the calendar page, event feed and drag/drop mutation under `/aop/terms/{term}/schedule/calendar`.
- Calendar reads use the requested term, while mutations verify the meeting block belongs to that term before updating it.
- Updated schedule links and navigation; the compatibility GET redirects to the user's selected term.
- Verified non-active-term rendering and feeds plus cross-term mutation rejection; the full suite passes 92 tests / 323 assertions.

## Explicit calendar release `7a40a49` — deployed 2026-11-15
- Atomically deployed the calendar route slice without a schema migration; release `8809a40` remains available for rollback.
- Rebuilt production caches, reloaded PHP-FPM and nginx, and verified cached routes, real production calendar rendering, SQLite integrity, HTTPS login and service health.

## Explicit office-hours term boundary — development checkpoint 2026-11-15
- Moved administrator office-hour pages, block mutations, locks and scheduling preferences under `/aop/terms/{term}/schedule/office-hours`.
- Reads and writes use the requested term; block mutations reject mismatched term or instructor ownership, and update now enforces the existing owner-or-administrator rule.
- Preserved instructor-portal compatibility against the active term and added a selected-term redirect for the legacy administrator GET.
- Verified non-active-term operations, preference isolation and cross-term rejection; the full suite passes 96 tests / 346 assertions.

## Explicit office-hours release `1249eb5` — deployed 2026-11-15
- Atomically deployed the office-hours route slice without a schema migration; release `7a40a49` remains available for rollback.
- Rebuilt production caches, reloaded PHP-FPM and nginx, and verified cached routes, real production office-hours rendering, SQLite integrity, HTTPS login and service health.

## Explicit schedule-studio term boundary — development checkpoint 2026-11-15
- Moved the schedule studio and distribution preview under `/aop/terms/{term}/schedule`.
- Studio queries, issue links, mutation redirects and term-aware schedule pages preserve the requested term.
- Kept the legacy studio GET as a selected-term redirect that preserves query filters.
- Verified non-active-term rendering and distribution preview behavior; the full suite passes 99 tests / 354 assertions.

## Explicit schedule-studio release `e639dc2` — deployed 2026-11-15
- Atomically deployed the studio route slice without a schema migration; release `1249eb5` remains available for rollback.
- Rebuilt production caches, reloaded PHP-FPM and nginx, and verified cached routes, real production studio rendering, SQLite integrity, HTTPS login and service health.

## Explicit schedule-grid term boundary — development checkpoint 2026-11-15
- Moved grid selection, instructor/room grids and PDF exports under `/aop/terms/{term}/schedule/grids`.
- Grid and PDF queries use the requested term; navigation and print links preserve it.
- Kept the legacy grids GET as a selected-term redirect.
- Verified non-active-term room grids and PDF output; the full suite passes 101 tests / 361 assertions.

## Explicit schedule-grid release `1929c48` — deployed 2026-11-15
- Atomically deployed the grid route slice without a schema migration; release `e639dc2` remains available for rollback.
- Rebuilt production caches, reloaded PHP-FPM and nginx, and verified cached routes, real production grid rendering, SQLite integrity, HTTPS login and service health.

## Explicit reports and exports term boundary — development checkpoint 2026-11-15
- Moved reports and registrar, instructor, room and compatibility term exports under `/aop/terms/{term}/schedule/reports`.
- Report queries and streamed CSV generation use the requested term; schedule navigation preserves it.
- Kept the legacy reports GET as a selected-term redirect and adapted retired publish/lock compatibility calls.
- Verified non-active-term report rendering and CSV content; the full suite passes 103 tests / 370 assertions.
