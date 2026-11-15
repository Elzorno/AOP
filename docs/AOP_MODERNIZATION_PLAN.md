# AOP modernization plan

Prepared 14 November 2026. Status: phases 1–4 are deployed. All supported scheduling writes use typed commands and shared transactional mutation services; the write-path inventory is recorded in `docs/SCHEDULE_WRITE_PATH_INVENTORY.md`. Phase 5 is in its expand/reconcile stage with non-destructive reconciliation and constrained staff references deployed.

Current production release: `d35caa5` through `/var/www/aop-current`. The isolated suite passes 126 tests / 509 assertions. Phase 4 is complete. Phase 5 shared reads now include mutation conflicts, calendar, workload, suggestions, readiness, schedule home and section cards, distribution policy metrics, grids, reports and registrar/instructor/room exports. Production has 37 sources and 37 events with zero missing, mismatched, unmatched or incorrectly linked records. Release `9235238` is the immediate application rollback target. Verified pre-release database checkpoint: `/root/aop-backups/20261115T175415Z-pre-d35caa5`.

## Outcome and constraints

Evolve the existing application into a dependable scheduling workspace with one authoritative event model, explicit term context, consistent validation, durable user-to-instructor links, and repeatable secure releases. Keep the current Laravel/Blade approach and SQLite initially. Change those choices only if measured requirements justify it.

Preserve the current product contract:

- Build → Validate → Export; readiness is advisory for registrar handoff, not a mandatory publication gate.
- Direct registrar CSV export, including sections without meetings, intentional `STAFF` assignments and asynchronous online sections.
- Instructor and room grids, Letter landscape printing, PDF output and historical token-protected publication downloads.
- Classes, open labs, lab-tech workload limits, office hours and instructor preferences.
- Syllabus generation remains retired. Term-wide editing locks and creation of new publication snapshots remain retired. Per-instructor office-hours locks are a separate supported control.
- Existing term data, identifiers, account access, export compatibility and stored artifacts must survive each release.

The recommendations are a target design. The current business rules remain authoritative until an explicitly documented implementation changes them. This document does not itself change application behavior.

## 0. Preservation checkpoint — completed

Backup: `/root/aop-backups/2026-11-14T150352Z`

| Item | Captured / verified |
| --- | --- |
| Project | Full application archive, including `.git`, uncommitted/untracked/ignored files, `.env`, dependencies, compiled assets and storage |
| Git baseline | HEAD `4d2eaad5cc1860c670a87d4693361b8f661de531`; 84 working-tree entries; separate staged/unstaged/combined patches and status |
| Database | Consistent online SQLite snapshot, separate from the live-file archive |
| Deployment evidence | Selected Nginx/PHP/SSH/firewall/APT/systemd/cron/logrotate configuration, package inventory and existing security review |
| Restore verification | 14,320 regular files checked against the extracted archive; stable project paths also matched the live pre/post inventories |
| Database verification | `integrity_check=ok`, zero foreign-key violations; 26 table counts and snapshot hash matched the restored database |
| Access | Backup directory mode 0700; sensitive backup files restricted to root |

See the backup's `manifest.json`, `SHA256SUMS`, `database-verification.json` and `RESTORE.md`. No live service stop or production restore was performed. This verifies file/database restoration, not a booted application recovery. The database snapshot is transaction-consistent; filesystem capture was online, so logs/cache are not an atomic snapshot with the database. The checkpoint is on the same server and needs an independently stored copy for disaster recovery.

## Delivery order

| Phase | Main deliverable | Prerequisite | Release gate |
| --- | --- | --- | --- |
| 1 | Isolated, reproducible development/test baseline | 0 | Current behavior captured; tests cannot reach production data |
| 2 | Security and maintenance release | 1 | Relevant advisories addressed and host controls verified |
| 3 | Explicit term context and instructor identity | 2 | Cross-term and cross-user access tests pass |
| 4 | One transactional scheduling mutation path | 3 | Every supported write path enforces identical rules |
| 5 | Authoritative scheduled-event storage | 4 | Migration reconciliation and recovery rehearsal pass |
| 6 | Versioned policy evaluation | 5 | Historical behavior preserved; approved policy fixtures pass |
| 7 | Unified scheduling workspace | 4–6 | Core tasks become simpler without losing validation/accessibility |
| 8 | Release automation, recovery and legacy cleanup | Incrementally from 1; finish after 7 | Reproducible release and application restore demonstrated |

Release each phase in bounded changes. Security work does not wait for a UI redesign. Establish deployment and backup practices early and complete their automation in phase 8.

## 1. Establish a reproducible baseline

1. Create an isolated development checkout from the preserved deployed tree. A checkout of HEAD alone is insufficient: it omits existing work. Preserve the deployment's staged/unstaged state in the backup, then make a reviewed baseline commit in the development checkout, excluding secrets, databases, generated storage and dependencies from Git. Do not reset, stash, clean or overwrite the live checkout.
2. Give development and tests separate environment files, storage and databases. The preserved `.env` and cached configuration contain production paths: remove copied runtime configuration caches in the isolated checkout and replace connection settings before booting Laravel. Set test mail/cache/queue/session drivers to isolated implementations and use synthetic fixtures.
3. Add a preflight that checks the effective database path and environment before any test suite, migration or reset command. SQLite `:memory:` must be verified after configuration loading; PHPUnit XML alone is insufficient when production configuration is cached.
4. Install development dependencies from the lockfile in the isolated checkout with an unprivileged account, reviewing package scripts before enabling them. Record PHP, dependency and asset-build versions.
5. Run the existing suite, including the 28 AOP-specific feature methods. Record actual results; repair baseline failures without removing assertions merely to obtain a pass. Capture stable fixtures for registrar CSV structure, room/instructor output and retired-workflow compatibility.
6. Reconcile README/deployment instructions with the implemented product. Mark historical phase entries as history; remove current instructions that still require syllabus generation or schedule publication locks.

**Acceptance:** the exact starting source is recoverable; a clean isolated checkout can reproduce the app and build assets; tests demonstrably use disposable data; any baseline failures are recorded and resolved or explicitly tracked before dependent changes.

**Rollback:** discard the isolated checkout. Production behavior is unchanged.

## 2. Address security and maintenance first

Use `security-reviews/2026-11-14-aop/report.md` and its advisory JSON as the starting evidence. Refresh advisories when implementation begins; the report's 50 entries are dependency matches, not 50 proven exploit paths.

- Update Laravel and affected compatible dependencies together in staging, including the reachable Symfony request-header parser issue. Select then-current supported patch releases rather than permanently fixing this plan to today's version numbers. Update lockfiles, rebuild assets and rerun the production dependency audit. Investigate applicable advisories; document justified remaining exceptions with an owner and review date.
- Establish trusted HTTPS for the intended internal hostname, redirect cleartext requests, set secure session cookies and verify any reverse-proxy trust configuration. Test actual cookies and login/password flows.
- Make application code deployment-owned and read-only to the web account. Restrict `.env`, cached configuration and SQLite files; account for SQLite directory access and journal/WAL files. Retain required writes for storage and cache. Preserve `APP_KEY`; do not run initial-install key generation or seeding against the existing deployment.
- Confirm management and web-client networks before introducing firewall rules. Create and test named administrative access before restricting direct root SSH; retain a recovery session/console during the change.
- Confirm the actual external/local patch process, address missing restart handling, and coordinate restarts of services with stale libraries. PHP2Shell's original core patches were already active at the review checkpoint.
- Disable unused PHP upload progress in startup configuration and verify workers load the setting. Apply compatible process isolation incrementally.
- Review CSV/form output handling and bring third-party browser scripts under the asset/dependency process where practical, including the CDN-loaded calendar. Preserve registrar data formatting while preventing spreadsheet-formula injection where applicable.

**Acceptance:** login is protected by trusted TLS; expected cookies/headers are observed; unrelated local identities cannot read secrets/database; web processes cannot rewrite code; approved network access works; required services use current libraries; applicable dependency findings are resolved or explicitly tracked; scheduling/export/PDF regression checks pass.

**Rollback:** use the previous code/vendor/assets release for application regressions. Restore individual configuration changes from the checkpoint if needed, with management access retained. Do not restore the database merely to undo a package or configuration change.

## 3. Make term and identity boundaries explicit

Primary files: `routes/web.php`, scheduling controllers, `DashboardController`, `InstructorPreferenceController`, `OfficeHoursController`, `User`, `Instructor` and profile/account handling.

- Introduce term-scoped routes such as `/aop/terms/{term}/schedule`, scoped resource binding and a persistent per-user selected-term preference. Controllers and services receive a `Term`; they do not infer write targets from the globally active term.
- Convert forms, calendar requests, exports, bulk actions and cloning to carry explicit term context. A mismatch between route term and resource ownership is rejected. Compatibility GET routes may redirect to a selected/default term; legacy writes must have an unambiguous target and must never silently follow a changed global term.
- Add a nullable, unique `users.instructor_id` foreign key for the current one-account/one-instructor model. Inventory existing associations first. Backfill only unambiguous, verified mappings; retain a review list for duplicates or missing matches. Leave unresolved accounts without instructor access until deliberately linked.
- Make account email changes independent of instructor ownership. Use Laravel policies for schedule administration, instructor self-service, exports and account management. Keep the existing admin role initially; introduce additional roles only for an identified workflow.
- Keep historical publication tokens as a distinct access mechanism; do not turn them into general application access.

**Acceptance:** two administrators can work on different terms concurrently; stale tabs cannot mutate another term; changing an account's email never gains another instructor's access; unauthorized direct requests fail; existing legitimate instructor access and public historical links still work.

**Rollback:** retain additive columns and compatibility routes. Reverting authorization must not reintroduce email-based access after account links are in use; roll forward a narrow fix if necessary.

## 4. Consolidate every scheduling write

Primary files: `MeetingBlockMutationService`, `ScheduleConflictService`, event/meeting/open-lab/office-hours/calendar/section controllers, `TermScheduleCloneService`, `Term::cloneToDraft()`, and import/console operations.

- Introduce a shared scheduling command service and typed inputs for create, update, delete, staff reassignment, bulk assignment and clone operations. Extend the existing mutation service rather than maintaining a parallel permanent implementation.
- Make all entry points use that service, including changes to a section instructor that affect existing meetings. Consolidate the two current cloning implementations and specify which assignments, capacities, preferences and dates are copied.
- Centralize authorization, term ownership, time normalization, modality/room requirements, resource conflicts, instructor office-hours restrictions, lab-tech hour limits and configured institutional enforcement. Keep advisory findings distinct from hard rejection reasons.
- Validate and persist atomically. For SQLite, reserve/serialize the write transaction before the conflict read, handle busy errors with bounded retries, and recheck conditions after a retry. A generic transaction or unsupported row-lock assumption is not sufficient. Add an event revision field to reject stale edits rather than silently overwrite them.
- Add a validation-preview endpoint using the same evaluator. Replace the browser's separate conflict approximation with those results, while retaining immediate basic form feedback.
- Record actor, term, operation, affected IDs and appropriate before/after changes in the existing audit/change-log mechanism. Avoid credentials and unnecessary personal data in logs.

**Acceptance:** equivalent form/calendar/bulk requests produce equivalent outcomes; invalid edits leave no partial data; instructor reassignment is checked; two simultaneous conflicting submissions cannot both succeed; stale edits return a readable conflict response; calendar errors revert the visual change. Use a file-backed disposable SQLite database for multi-process contention tests, not separate in-memory databases.

**Rollback:** keep controllers as thin adapters and release each converted path separately. Preserve compatible data formats until all entry points use the service.

## 5. Make scheduled events authoritative

Primary files: `ScheduledEvent`, `ScheduledEventStaffAssignment`, source-detail models, `ScheduleEventSyncService`, `ScheduleEventQueryService`, read/report/grid/readiness services and migrations.

Target data ownership:

| Record | Owns |
| --- | --- |
| Catalog / offering / section | Academic identity, term offering, modality, enrollment/capacity and intentional faculty assignment |
| Scheduled event | Term, type, weekly recurrence, local start/end times, applicable date range, room and revision |
| Type-specific detail | A constrained link to its event plus class/open-lab/office-hour-specific attributes; no independent mutable copy of event time or room |
| Staff assignment | Event, role and a real foreign-key relationship to an instructor or lab tech |
| Term | Dates, timezone, policy version and calendar exceptions |

For current staff types, use constrained instructor/lab-tech foreign keys with an exactly-one-target rule, rather than unconstrained `staff_type`/`staff_id` references. `STAFF` remains a section placeholder, never a fabricated person record.

Perform an expand → reconcile → cut over → contract migration:

1. **Expand:** add relationships and constraints without dropping old fields. Retain stable IDs and mappings between source records and events. During this step the existing source records remain authoritative, with event projections maintained through the phase-4 service in the same transaction.
2. **Reconcile:** build an idempotent dry-run/backfill command. Identify missing/duplicate events, orphan staff, mismatched times/rooms/days and cross-term links. Reconcile every existing term and validate one-to-one source/event mappings. Compare calendars, conflicts, workload totals and export output using normalized data, not only row counts.
3. **Cut over:** switch reads and writes together in a controlled deployment after reconciliation. Scheduled events become authoritative. If a rollback window requires legacy mirrors, update them in the same transaction through a one-way adapter; remove recursive model hooks and reject direct legacy writes.
4. **Replace dangerous legacy maintenance:** before cutover, replace or disable the existing `aop:sync-scheduled-events` delete-and-rebuild behavior. Once events are authoritative, a command must not delete them and rebuild from stale detail tables. Use validation/repair commands with explicit authority and dry-run output.
5. **Contract:** remove duplicate time/room fields and obsolete adapters only in a later release after parity and rollback requirements are satisfied. Keep historical publication artifacts and their download behavior intact.

Test both upgrades from the preserved schema and fresh database construction. Historical migrations currently call application models/services; maintain compatible migration behavior so refactoring those classes does not break a fresh install.

**Acceptance:** one authoritative time/room record per event; database-enforced staff references; no orphan/detail/event mismatches; identical expected exports and room/instructor schedules; deletion/reassignment semantics tested; clone and import operations preserve invariants; no destructive rebuild path remains.

**Rollback:** before any data migration, take a fresh consistent database snapshot. Prior to cutover, revert reads to the original model only while it is still current. After cutover/new writes, rollback needs a verified compatibility mirror or a forward repair. Restoring an old database would discard subsequent user work and is not a routine rollback. Dropping legacy columns ends that compatibility window.

## 6. Separate and version institutional policy

Primary files: `ScheduleRuleWarningService`, `ScheduleBlockLibrary`, `ScheduleDistributionService`, readiness/dashboard/report logic and policy storage.

- Define deterministic results with rule ID, category, severity, affected resources, explanation and remediation link. Reuse one evaluator for preview, saved schedule readiness, reports and dashboard summaries.
- Distinguish structural validity, resource conflicts and institutional policy. Preserve existing hard resource checks and the current direct-export behavior; advisory readiness must not become an implicit publication lock.
- Capture a policy version per term. Start with current behavior as the compatibility version: buffer handling, instructional-minute assumptions, full-time office hours, lab-tech hour limits and distribution targets.
- Add date-aware occurrence calculations with term timezone, holidays, cancellations, make-up sessions and shortened terms. Keep recurrence representation simple; add exceptional dates without generating unrelated calendar complexity.
- Have the academic owner resolve actual policy interpretation before activating changed calculations. Define asynchronous-online treatment explicitly rather than treating absent meetings as missing instruction. Do not present hardcoded thresholds as independently verified legal compliance.
- Preserve historical terms' assigned policy and reproducible results. Show which policy and assumptions generated an issue.

**Acceptance:** fixed fixtures cover term boundaries, holiday/make-up dates, time buffers, shortened terms, lecture/lab allocation, online sections, full-time office hours and staff hour limits; dashboard/readiness/preview agree; changing policy affects only explicitly selected terms.

**Rollback:** select the previous compatible evaluator/policy version for affected terms. Preserve calculated evidence and do not silently reinterpret historical exports.

## 7. Refine the scheduling workspace

Primary files: Schedule Studio, section cards, calendar, grids, instructor dashboard, shared Blade components, `resources/js/app.js` and styles.

- Keep the selected term prominent and preserve it through every action. Put section-list, calendar and issues views over the same term/query model.
- Support a compact flow: choose course → create offering/section → assign faculty or `STAFF` → place meetings → resolve issues → export. Allow adding/editing related data in context with clear cancellation and validation feedback.
- Add office-hour/open-lab/staff views to the same scheduling context while preserving type-specific editing needs.
- Use the phase-4 preview and mutation API. Debounce previews, handle stale revisions explicitly and revert failed drag/resize actions. Display conflict and policy findings consistently with links to affected events.
- Preserve server-rendered pages and progressive enhancement. Use reusable controls, keyboard-operable actions, labeled inputs, focus/error management, meaningful status text and accessible color contrast. Support smaller screens while optimizing scheduling density for desktop.
- Keep registrar CSV and printable/PDF outputs directly available. Preserve the existing column contract, `STAFF`, online location conventions, sections without meetings and historical downloads.

**Acceptance:** measure the current and new steps for creating a section, assigning staff, resolving a conflict and exporting; reduce unnecessary navigation without hiding required information. Complete those flows by keyboard; retain draft input after validation errors; verify live views and print/export data agree.

**Rollback:** retain old views during an initial rollout while using the same backend and schema; reverting presentation must not revert data authority.

## 8. Complete release, recovery and documentation discipline

- Build versioned release artifacts with reviewed source, lockfiles, production dependencies and compiled assets. Keep runtime secrets, database and writable storage outside release artifacts. Record a release identifier and artifact checksum.
- Provide one documented deployment path: preflight → current backup → stage release → migration checks → controlled switch → smoke checks → record result. If ZIP/FileZilla delivery remains useful, generate it from the same release artifact; avoid manually assembled partial overwrites.
- Make production caches release-specific. Keep `APP_KEY` stable. Never run destructive reset commands, initial-install seeding or key generation as standard upgrades.
- Add a minimal automated pipeline for functional/authorization/migration tests, asset builds and dependency auditing. Treat exceptions as explicit tracked decisions, not silently ignored failures.
- Schedule database backups and protect stored exports. Create an independently stored, encrypted backup using a destination selected by the operator. Proposed starting service targets are a daily recovery point plus a pre-release backup and restoration within one hour; confirm acceptable data loss/downtime and prove targets by rehearsal before claiming them.
- Perform a full isolated application recovery, including database, application key, exports, login and representative scheduling/export flows. Verify restore credentials and certificates can be recovered through the operator's existing secure process; the current checkpoint is not a complete OS/hypervisor image.
- Remove unused publication-generation code and retired syllabus references once compatibility is demonstrated. Retain historical migrations/artifacts required for upgrades and downloads. Keep README, rules, decisions, release notes and runbooks aligned with the actual release.

**Acceptance:** a clean environment can build and stage the release; representative application recovery succeeds; a previous compatible release can be selected without losing user writes; off-host backup retrieval is demonstrated; operator instructions match current functionality.

## Decisions to resolve at the phase that needs them

| Decision | Safe working assumption | Needed before |
| --- | --- | --- |
| TLS hostname, trust source and any proxy | Internal-only access; verify topology rather than assume public exposure | Phase 2 transport change |
| Management networks and recovery console | Preserve existing working administration until replacement is tested | Phase 2 SSH/firewall change |
| Instructor-account ambiguities | No new instructor access from an ambiguous email match | Phase 3 identity backfill |
| Term timezone, holidays and policy interpretation | Preserve current calculations as a versioned baseline | Phase 6 changed policy activation |
| Backup destination, retention and recovery targets | Current verified local checkpoint remains protected | Phase 8 disaster-recovery completion |
| Database scalability | Retain SQLite; measure concurrency and operational needs | Any proposed database-engine change |

## Completion criteria

The modernization is complete when the security remediation release is verified; all scheduling writes are term-scoped and authorized; the event model has a single authoritative owner; all views evaluate the same versioned rules; current registrar/print/instructor workflows pass regression checks; releases and recoveries are reproducible; and documentation reflects implemented behavior. Preserve meaningful tests and add coverage for new boundaries; do not create tests merely to mirror implementation details.

Next executable step: continue phase 4 by consolidating term-scoped office-hours mutations, followed by instructor reassignment, bulk assignment and cloning paths.

## Implementation checkpoint — 14 November 2026

Isolated workspace: `/srv/aop-workspaces/modernization`, branch `modernization/2026-11-14`.

- Captured the deployed source, including pre-existing uncommitted work, in baseline commit `68cf437` without copying production secrets or data into the development runtime.
- Added test and database-maintenance preflights. Tests require uncached configuration and disposable SQLite storage; destructive maintenance requires an exact `AOP_EXPECTED_DATABASE` path.
- Installed locked development dependencies as the unprivileged `aop-build` account. The complete baseline now passes 65 tests / 234 assertions and the Vite production build.
- Updated the compatible Laravel 12 stack, including Symfony HTTP Foundation 7.4.23. Composer audit reports zero advisories.
- Updated audited browser dependencies, bundled FullCalendar through Vite instead of CDN scripts, and reduced npm audit to zero advisories.
- Added spreadsheet-formula neutralization to current CSV generation and download-time sanitization for historical CSV/ZIP artifacts. Stored snapshots remain byte-for-byte unchanged. The six existing stored CSV artifacts were inventoried and contain no formula-leading cells.
- Added configurable Cloudflare Tunnel proxy trust and HTTPS enforcement for `syllabi.elzorno.com`. The observed connector address is `192.168.1.167`; external responses now verify the HTTPS redirect, secure session cookies and transport/security headers.

Release `dc60a9d` was the initial security and phase-3 identity checkpoint: nullable unique account links, conservative unambiguous backfill, admin link management, and removal of email-based ownership. The production inventory resulted in zero links, so no access was granted implicitly.

## Deployment checkpoint — 15 November 2026

- Completed explicit term routing for the supported administrative scheduling, grid, readiness, report, export and retired-action paths. Instructor self-service remains intentionally scoped to the authenticated instructor and active term.
- Deployed transactional meeting-block mutations in `f63b59c`, including the form, unified-event and calendar entry points.
- Deployed transactional open-lab mutations in `c231c9c`, including form and unified-event entry points, hard room/lab-tech/workload validation, event projection and actor/term audit records.
- Deployed transactional office-hours mutations in `2a9cf98`, covering administrator and instructor self-service entry points with lock, ownership, conflict, projection and audit guarantees.
- Deployed atomic section instructor reassignment and bulk assignment in `f229c1e`. New assignments are checked against class and office-hour conflicts, including overlaps within the submitted bulk set, before any section changes.
- Consolidated the explicit and draft term clones in `cddb0bd`. Meeting blocks, optional office hours and open labs now use the shared mutation services inside one outer transaction; capacities are preserved, source locks/publications/history remain excluded, and a failed invariant check rolls back the complete target term.
- Deployed SQLite write serialization in `3143e7c`. A PHP 8.3-compatible connection begins `IMMEDIATE` transactions, retains nested savepoints, waits up to five seconds on a busy database, and retries scheduling transactions three times. A two-process file-backed test proves concurrent conflict reads serialize before either write proceeds.
- Added monotonic scheduled-event revisions in `a4ae9bd`. Calendar payloads include the current revision, successful drag/resize updates distribute the new revision to every weekday instance, and stale writes return a readable error without overwriting newer work.
- Deployed a shared meeting-block validation preview in `82dc3d0`. Create, update and preview normalize and evaluate the same payload; section-card forms debounce against the server endpoint instead of maintaining an independent conflict approximation. The production Vite asset was rebuilt and its public response verified.
- Consolidated section create/delete in `c3b824e`, completing the interactive section lifecycle transaction path. Cascaded meeting deletions and projection removal are atomic, and the section audit foreign key now uses `SET NULL` so deletion history survives with the original section ID retained in its payload.
- Closed the remaining direct clone write and enforcement bypass in `8faed83`: copied sections now use the section service, and institutional hard rules are evaluated by the shared meeting service for every entry point. Preview responses also expose advisory warnings from that evaluator.
- Replaced the delete-and-rebuild scheduled-event command in `bb17783` with a transactional, non-destructive repair. It restores source-backed projections while preserving unmatched events for the phase-5 reconciliation workflow.
- Added dry-run reconciliation in `e0435c4`; repair now requires `--repair` and retains the exact-database maintenance guard. The production audit found 37 source records and 37 projected events with no missing, mismatched or unmatched rows across four terms.
- Added constrained staff targets in `a65800c`. The expand-stage table retains legacy type/ID columns, adds instructor and lab-tech foreign keys, and enforces exactly one matching typed target. All 35 production assignments migrated cleanly and foreign-key validation found no violations.
- Completed the phase-4 write boundary in `92286e6`. Meeting blocks, open labs and office hours now require typed commands across form, calendar, unified-event and clone entry points; `docs/SCHEDULE_WRITE_PATH_INVENTORY.md` records the audited paths.
- Linked every type-specific source to its scheduled event in `5af0682` with nullable unique foreign keys for the expand stage. All 37 production links backfilled successfully, and reconciliation now checks link parity independently from event fields and staff.
- Cut schedule suggestions over to scheduled-event time, room and staff data in `6791bdc`. A divergence test proves suggestion availability follows the event ledger rather than duplicated source fields.
- Cut readiness and schedule-home conflict/summary reads over in `26b276c` through a shared in-memory event adapter. A divergence test proves readiness follows event time and room fields while existing view contracts remain intact.
- Cut room and instructor grid selection/rendering over in `9be030e`, including print and PDF paths. A divergence test proves a record appears under its event-owned room rather than its legacy source room.
- Cut reports and registrar, instructor and room CSV exports over in `cc8ecdf`. Existing spreadsheet contract tests remain unchanged, and the divergence fixture proves room export selection and values follow the event ledger.
- Cut term distribution metrics over in `9235238`. A divergence test proves Friday coverage and derived policy percentages use event recurrence rather than source recurrence.
- Cut section-card conflict counts, badges and HTMX refresh data over in `d35caa5`. The readiness divergence fixture now verifies both the aggregate page and an individual card use event-owned time and room data.
- Widened `schedule_change_logs.section_id` to nullable so non-section schedule operations can use the same audit mechanism without placeholder section records.
- Verified 126 tests / 509 assertions, migration status, SQLite integrity and foreign keys, reconciliation and link parity, section-card service resolution and HTTPS login. Release `9235238` is the immediate application rollback target.
