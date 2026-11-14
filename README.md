# Academic Ops Platform (AOP)

Academic Ops Platform is a Laravel-based internal academic scheduling application.

## Current release

- Release: `1.0.0`
- Stack: Laravel 12, PHP 8.3, SQLite
- Primary modules:
  - Terms
  - Instructors
  - Rooms
  - Catalog
  - Schedule planning and readiness checks
  - Open labs and lab-tech workload tracking
  - Historical schedule publication downloads

## Core scheduling features

- Schedule Studio workflow: Build → Validate → Export
- Offerings, sections, and meeting blocks
- Room and instructor conflict detection with configurable term buffer minutes
- Instructor office-hours tracking and compliance checks
- Readiness review for conflicts, instructional-minutes compliance, and office-hours compliance
- Historical token-protected schedule downloads
- Clone-last-year schedule workflow to seed a new term from an existing term

## Product boundaries

- Readiness findings advise administrators; they do not block registrar export.
- `STAFF` is an intentional unassigned-faculty placeholder.
- Asynchronous online sections and sections without meetings remain exportable.
- Syllabus generation and creation of schedule-publication snapshots are retired.
- Existing publication snapshots remain available through their protected historical links.

## Deployment notes

AOP is intended to run as an internal administrative application.

Minimum host expectations:

- PHP 8.3+
- Composer
- SQLite 3
- Web server pointing to `public/`
- writable `storage/` and `bootstrap/cache/`

See `docs/AOP_DEPLOY_CHECKLIST.md` for a clean production deployment workflow.

## First-time setup

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
mkdir -p database
touch database/database.sqlite
AOP_EXPECTED_DATABASE="$PWD/database/database.sqlite" php artisan migrate --force
php artisan db:seed --force
php artisan optimize
```

Then configure your first admin account using your preferred local admin/bootstrap process.

## Post-deploy maintenance

```bash
php artisan optimize:clear
AOP_EXPECTED_DATABASE=/absolute/path/to/database.sqlite php artisan migrate --force
php artisan optimize
```

## Project continuity

- `docs/AOP_PHASE_LOG.md` tracks phase-by-phase changes.
- `docs/AOP_DECISIONS.md` captures product decisions.
- `docs/AOP_RULES.md` captures scheduling rules and assumptions.
