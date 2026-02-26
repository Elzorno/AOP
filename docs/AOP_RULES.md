# AOP Business Rules

This document describes business rules in plain language and points to where they live in code.

## Active Term
- Scheduling actions operate on the Term where `terms.is_active = 1`.
- If no active term exists, scheduling pages should instruct the user to set one.

**Code:**
- `app/Http/Controllers/Aop/Schedule/*` (controllers typically call `Term::where('is_active', true)->first()`)

## Offerings and Sections
- Offerings belong to a Term and reference a Catalog Course.
- Sections belong to an Offering.

## Meeting Blocks
- A Section must have at least one Meeting Block (unless future rules allow online sections to omit them).
- Each Meeting Block includes:
  - meeting type
  - days of week (JSON)
  - start and end time
  - optional room

## Rooms
- In-person/hybrid meeting blocks require a room.

## Office Hours
- Office hours are defined per instructor per term.
- Office hours may be "locked" per instructor.

## Conflict Checks
Readiness checks must detect conflicts:
- **Room conflicts:** class vs class (same room, overlapping days and times)
- **Instructor conflicts:**
  - class vs class
  - office vs office
  - class vs office

**Code:**
- `app/Http/Controllers/Aop/Schedule/ScheduleReadinessController.php`
- `app/Services/ScheduleConflictService.php`

## Instructional Minutes Check (ODHE)
Compute scheduled minutes for each section and compare to required minutes.

### Required Minutes
- Lecture: `credits * 750`
- Non-homework-intensive lab: `credits * 2250`

### Scheduled Minutes
For each meeting block:
- duration minutes = `ends_at - starts_at`
- weekly minutes = `duration * number_of_days`
- term minutes = `weekly_minutes * term.weeks_in_term`

A section **passes** if `scheduled_minutes >= required_minutes`.

**Code:**
- `app/Http/Controllers/Aop/Schedule/ScheduleReadinessController.php` (`computeInstructionalMinutes()`)
- UI: `resources/views/aop/schedule/readiness/index.blade.php`
