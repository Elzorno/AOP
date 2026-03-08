# Academic Ops Platform (AOP)

Academic Ops Platform is a Laravel-based internal scheduling and syllabus management application for academic operations.

## Current release

- Release: `1.0.0`
- Stack: Laravel 12, PHP 8.3, SQLite
- Primary modules:
  - Terms
  - Instructors
  - Rooms
  - Catalog
  - Schedule planning and readiness checks
  - Public schedule publication snapshots
  - Syllabi rendering and render history

## Core scheduling features

- Active-term scheduling workflow
- Offerings, sections, and meeting blocks
- Room and instructor conflict detection with configurable term buffer minutes
- Instructor office-hours tracking and compliance checks
- Readiness review for conflicts, instructional-minutes compliance, and office-hours compliance
- Published public schedule snapshots with downloadable exports
- Clone-last-year schedule workflow to seed a new term from an existing term

## Security posture

Recent hardening includes:

- self-registration disabled by default
- schedule lock enforced server-side
- publish requires a locked schedule
- public published links use token-based access and throttling
- stricter validation for schedule data entry
- response security headers for deployed environments
- SQLite foreign-key enforcement at runtime

## Deployment notes

AOP is intended to run as an internal administrative application.

Minimum host expectations:

- PHP 8.3+
- Composer
- SQLite 3
- Web server pointing to `public/`
- writable `storage/` and `bootstrap/cache/`

For syllabus rendering, install:

- `libreoffice` for PDF conversion from the rendered DOCX template
- PHP `ZipArchive` support so the app can populate DOCX template placeholders

See `docs/AOP_DEPLOY_CHECKLIST.md` for a clean production deployment workflow.

## First-time setup

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
mkdir -p database
touch database/database.sqlite
php artisan migrate --force
php artisan db:seed --force
php artisan optimize
```

Then configure your first admin account using your preferred local admin/bootstrap process.

## Post-deploy maintenance

```bash
php artisan optimize:clear
php artisan migrate --force
php artisan optimize
```

## Project continuity

- `docs/AOP_PHASE_LOG.md` tracks phase-by-phase changes.
- `docs/AOP_DECISIONS.md` captures product decisions.
- `docs/AOP_RULES.md` captures scheduling rules and assumptions.
