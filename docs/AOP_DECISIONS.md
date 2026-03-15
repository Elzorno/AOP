# AOP Locked Decisions

This file is the **source of truth** for decisions that should persist across development iterations and across ChatGPT sessions.

## Workflow
- Patches are delivered as ZIP files mirroring the repo structure.
- Stakeholder workflow: **unzip on Windows → upload/overwrite via FileZilla → run artisan clear commands**.
- **Continuity Rule**: The `docs/` folder **MUST** be updated at each development iteration to maintain an accurate source of truth (Phase Log, Rules, and Decisions).

## Scheduling Domain Decisions
- The app schedules sections within an **active Term**.
- **Draft Mode**: Terms can exist in `draft` status. Admins can clone existing terms into a draft to iterate on schedules without affecting published data.
- The Term has configurable scheduling parameters (weeks in term, slot minutes, buffer minutes).
- **Suggestions**: The platform provides a "Suggest Slot" utility that automatically identifies the next available conflict-free window for an instructor and identifies an available room.
- **Visual Scheduling**: A drag-and-drop calendar interface provides a visual week view of meeting blocks, highlighting overlaps and allowing real-time adjustments.
- **Mutation Safety**: Meeting block updates must run through one shared validation path so calendar edits and form edits enforce identical rules and conflict checks.
- **Calendar Error Contract**: Calendar mutation failures return JSON validation responses with readable messages; UI must revert failed drag/resize actions and show a visible inline status message.
- Scheduling readiness must provide **pass/fail** checks for:
  - completeness (missing instructors / meeting blocks / rooms)
  - conflicts (room, instructor; includes office hours)
  - instructional minutes (ODHE / SSU rules)

## ODHE / Instructional Minutes
- Lecture courses: **750 minutes** of in-class instruction per credit hour.
- Labs (non-homework intensive): **2250 minutes** of in-lab instruction per credit hour.
- For SSU Cyber Program use: treat labs as **non-homework intensive** (homework-intensive lab rules are out of scope).

## UI Intent
- Keep the Schedule home page as a **clean dashboard**.
- Prefer workflow tiles over long link lists.
- Schedule home acts as a workflow board with **Build / Validate / Publish** stages and active-term summary counts.
- Readiness should act as the primary quality gate (visibility-first).
