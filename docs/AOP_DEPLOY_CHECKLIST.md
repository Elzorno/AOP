# AOP Deploy Checklist

## 1. Host prerequisites

Install and confirm:

- PHP 8.3+
- Composer
- SQLite 3
- `pdo_sqlite` PHP extension
- `mbstring`, `xml`, `ctype`, `json`, `tokenizer` PHP extensions
- `pandoc`, `wkhtmltopdf`, and `libreoffice` if syllabi DOCX/PDF exports are required

## 2. Application files

- Deploy the repo so the web server serves from `public/`
- Ensure `storage/` and `bootstrap/cache/` are writable by the web server user
- Keep `.env` out of version control

## 3. Environment configuration

Start from `.env.example` and set at minimum:

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL=` to the real internal URL
- `DB_CONNECTION=sqlite`
- `DB_DATABASE=` to the full SQLite file path
- `AOP_VERSION=1.0.0`
- `AOP_ALLOW_SELF_REGISTRATION=false`

Recommended when HTTPS is enabled:

- `SESSION_SECURE_COOKIE=true`

## 4. Database/bootstrap

```bash
mkdir -p database
touch database/database.sqlite
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force
```

## 5. Optimization

```bash
composer install --no-dev --optimize-autoloader
php artisan optimize:clear
php artisan optimize
```

## 6. Web-server checks

- confirm the document root points to `public/`
- confirm `/storage`, `.env`, and source files are not directly web-accessible
- confirm the application is reachable at `/login`
- confirm the root URL redirects to login or dashboard

## 7. Functional smoke test

- sign in as an admin account
- confirm Terms, Instructors, Rooms, Catalog, Schedule, and Profile pages load
- confirm an active term can be selected
- confirm schedule grids render correctly
- confirm Readiness loads without errors
- confirm a schedule can be locked and published
- confirm the public published-schedule link opens and downloads work
- confirm syllabi HTML/JSON/DOCX/PDF generation works on the host

## 8. Release checklist

Before calling the deployment final:

- clear caches after file upload
- rerun migrations after every schema change
- verify `APP_DEBUG=false`
- verify `AOP_ALLOW_SELF_REGISTRATION=false`
- verify schedule publication URLs work from a browser
- verify at least one syllabus DOCX and PDF render completes successfully
