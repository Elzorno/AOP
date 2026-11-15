# Schedule Core

The schedule is organized around one event ledger and generic resource assignments. Classes, open labs, and office hours each keep their type-specific detail record, while `scheduled_events` is the shared scheduling record used for time, room, and staff coordination.

The Scheduled Events workflow is the common entry point for new class meetings and open labs. The calendar, room conflicts, instructor conflicts, lab-tech conflicts, and HR workload calculations read the shared ledger. Source-detail screens remain available for course-section administration and type-specific edits.

Run `php artisan aop:sync-scheduled-events` after a bulk import or direct database maintenance operation. It is read-only by default and reports missing, mismatched and unmatched projections. Use `--term=<id>` to inspect one term and add `--repair` to upsert current source records. Repair preserves unmatched projected events for explicit reconciliation; it never deletes and rebuilds a term's projection.

## Invariants

- An event has exactly one source-detail record.
- Room conflicts are evaluated across every event type.
- Staff conflicts are evaluated by `staff_type` and `staff_id`.
- Lab-tech limits apply to every event assigned to a lab tech, not only open labs.
- Office hours are instructor assignments in the same event ledger.
