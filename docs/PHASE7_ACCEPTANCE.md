# Phase 7 acceptance evidence

Prepared 16 November 2026. The preserved comparison point is commit `68cf437`; the deployed implementation reviewed here is release `24a08c5`.

## Core-task navigation measurements

An activation is a deliberate button, link or disclosure action. Typing and choosing a value are not counted; submitting the completed form is counted. Page transitions are listed separately. Measurements start on Schedule Studio with the intended term already selected and an existing offering/section when the task requires one.

| Task | Preserved baseline | Current implementation | Result |
| --- | ---: | ---: | --- |
| Create a section in an existing offering | 2 activations, 0 transitions: open **Add section**, submit | 2 activations, 0 transitions | No regression; input and validation remain inline. |
| Assign or change section faculty | 2 activations, 0 transitions: open **Edit section details**, submit | 2 activations, 0 transitions | No regression; `STAFF` and named faculty remain available inline. |
| Correct a known meeting conflict | 2 activations, 0 transitions: open **Edit meeting**, submit | 2 activations, 0 transitions | Same navigation count; current flow adds shared server preview, normalized advisory/error feedback and atomic validation. |
| Download the registrar CSV | 2 activations, 1 transition: open **Registrar Export**, download | 2 activations, 1 transition | No lock or publication step; desktop and mobile schedule navigation expose the same route. |

The preserved UI already placed the three editing tasks inline, so there is no honest click-count reduction to claim. Phase 7 retained those minimal paths while replacing implicit active-term behavior, duplicated browser validation and inaccessible failure states. Mobile schedule sub-navigation removes the need to return to Studio before moving among schedule views.

## Automated evidence

- The complete isolated suite passes 138 tests / 644 assertions.
- Explicit term-boundary tests cover Studio, calendar, readiness, reports and exports.
- The calendar page contract includes a skip link, visible-focus target, mobile schedule navigation, horizontally contained weekly grid, keyboard editor links, live save status, deterministic reversion and stale-revision recovery.
- Section-card and distribution previews sequence requests so old responses cannot replace newer input; errors and advisory findings use live status regions.
- Instructor and room exports are ordinary validated GET forms and work without JavaScript. Registrar export remains a direct link.
- The production Vite build contains the shared focus outline and narrow-calendar minimum width. Blade and route caches compile successfully.
- Production smoke checks verify the HTTPS login and current hashed asset return HTTP 200, the database passes integrity and foreign-key checks, and all 37 schedule sources reconcile to 37 authoritative events with no discrepancies.

## Responsive implementation evidence

- Shared content padding contracts at phone widths and expands from the `sm` breakpoint.
- Schedule navigation is horizontally scrollable on mobile rather than omitted.
- Editor and insight sidebars stack before the `lg` breakpoint.
- Studio cards, actions and disclosures wrap; the calendar scrolls inside its own labeled container at widths below its seven-day minimum.
- Report/export grids use one column by default and two columns only at `lg`; selection forms stack before `sm`.

## Manual browser evidence still required

The container has no browser automation runtime. Do not claim the visual/keyboard portion complete until an operator or browser pipeline verifies:

1. At 320 px, 768 px and desktop widths: no document-level horizontal scrolling; calendar-only horizontal scrolling is intentional.
2. Keyboard-only: skip link, mobile/desktop schedule navigation, disclosures, section forms, calendar editor links, report selection forms and registrar download have a logical focus order and visible focus.
3. A rejected calendar drag/resize visibly reverts; a stale revision focuses the error and exposes **Reload calendar**.
4. Validation input is retained after server errors on section and meeting forms.
5. Text, controls, advisory states and focus indicators meet the intended contrast in the deployed theme.

Phase 7 remains open until this real-browser pass is recorded. This limitation does not block continued Phase 8 release/recovery work.

## Usability follow-through — 17 November 2026

- Faculty accounts now receive a role-specific navigation model and never see administrator-only Terms, Catalog, People, Rooms, or Account Links destinations.
- The faculty workspace groups assigned classes, teaching preferences, and office hours on one page; it presents a next action, responsive course cards, plain-language availability entry, editable office-hour blocks, and a schedule-only print mode.
- Invalid faculty office-hour edits reopen the exact editor with submitted values retained. Deletion uses an inline explanatory confirmation rather than a browser-native prompt.
- Administrator navigation is grouped by Workspace, People & Places, and Settings. Schedule navigation now uses task language: Courses & Sections, Calendar, Faculty & Office Hours, Open Labs, Schedule Check, and Export.
- Mobile primary navigation uses a compact disclosed menu rather than a long row of setup destinations. Schedule navigation remains directly available within scheduling pages.
- The administrator home is a working-term launchpad with a single primary continuation action, a concise attention summary, direct links to six essential scheduling tasks, and secondary configuration links.
- Institution-wide current-term state is distinguished from an administrator's private working term. The duplicate term-selection action was removed.
- Schedule Builder header actions were reduced to contextual issue review, Calendar, and Export; less frequent destinations remain one click away in schedule navigation.
