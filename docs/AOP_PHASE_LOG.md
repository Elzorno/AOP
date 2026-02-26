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
