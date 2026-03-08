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
