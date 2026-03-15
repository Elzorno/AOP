# AOP Locked Decisions

This file is the **source of truth** for decisions that should persist across development iterations and across ChatGPT sessions.

## Workflow
- Patches are delivered as ZIP files mirroring the repo structure.
- Stakeholder workflow: **unzip on Windows → upload/overwrite via FileZilla → run artisan clear commands**.

## Scheduling Domain Decisions
- The app schedules sections within an **active Term**.
- **Draft Mode**: Terms can exist in `draft` status. Admins can clone existing terms into a draft to iterate on schedules without affecting published data.
- The Term has configurable scheduling parameters (weeks in term, slot minutes, buffer minutes).
- **Suggestions**: The platform provides a "Suggest Slot" utility that automatically identifies the next available conflict-free window for an instructor and identifies an available room.
- **Visual Scheduling**: A drag-and-drop calendar interface provides a visual week view of meeting blocks, highlighting overlaps and allowing real-time adjustments.
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
- Readiness should act as the primary quality gate (visibility-first).
