# Scheduling write-path inventory

Verified 15 November 2026. Supported interactive and clone writes use the same typed command and transactional mutation boundary.

| Record | Entry points | Typed input | Mutation boundary |
| --- | --- | --- | --- |
| Meeting block | section form, calendar preview/update, unified event form, term clone | `MeetingBlockCommand` | `MeetingBlockMutationService` |
| Open lab | open-lab form, unified event form, term clone | `OpenLabCommand` | `OpenLabMutationService` |
| Office hour | admin and instructor forms, term clone | `OfficeHourCommand` | `OfficeHourMutationService` |
| Section and instructor assignment | section form/delete, bulk assignment, term clone | validated section attributes and assignment value | `SectionInstructorAssignmentService` |

The mutation services own term/ownership checks, normalization, resource conflicts, workload and policy enforcement, audit records, scheduled-event projection, and transaction retry behavior. Controllers validate transport input and construct typed commands; they do not persist schedule records directly.

`ScheduleEventSyncService` is the projection adapter during phase 5. Its maintenance command is read-only unless `--repair` is explicit. The reset command is destructive administration rather than an interactive scheduling path and remains protected by exact database targeting and confirmation.

Read-only controllers and services still query legacy source tables until the phase-5 authoritative scheduled-event cutover. Offering, term, preference, lock, catalog, room, instructor and lab-tech administration are separate aggregate writes and are outside this schedule-event mutation inventory.
