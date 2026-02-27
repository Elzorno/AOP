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
