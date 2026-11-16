# AOP Deploy Checklist

## 1. Host prerequisites

Install and confirm:

- PHP 8.3+
- Composer
- SQLite 3
- `pdo_sqlite` PHP extension
- `mbstring`, `xml`, `ctype`, `json`, `tokenizer`, and `zip` PHP extensions

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
- `AOP_TRUSTED_PROXIES=` to the comma-separated Cloudflare connector IPs/CIDRs
- `AOP_ENFORCE_HTTPS=true` after the trusted proxy path is verified
- `SESSION_SECURE_COOKIE=true`

Do not use `*` for trusted proxies when the origin is reachable by other LAN clients.

## 4. First installation only

The following commands create a new installation. Never use them during an upgrade or recovery of the preserved production deployment: generating a new key invalidates encrypted data and sessions, while seeding may create or replace application data.

```bash
mkdir -p database
touch database/database.sqlite
php artisan key:generate
AOP_EXPECTED_DATABASE=/absolute/path/to/database.sqlite php artisan migrate --force
php artisan db:seed --force
```

## 5. Build a versioned release artifact

Build only from a clean reviewed checkout. The builder installs production Composer dependencies, performs a clean npm install, compiles Vite assets, excludes runtime secrets/data and writes a SHA-256 sidecar:

```bash
scripts/build-release.sh /absolute/path/to/checkout /absolute/path/to/artifacts
sha256sum --check /absolute/path/to/artifacts/aop-<release>.tar.gz.sha256
```

The artifact includes a `RELEASE` manifest with the full commit and lockfile hashes. It does not contain `.env`, SQLite databases, writable storage or `node_modules`.

## 6. Upgrade optimization

```bash
composer install --no-dev --optimize-autoloader
php artisan optimize:clear
php artisan optimize
```

## 7. Web-server checks

- confirm the document root points to `public/`
- confirm `/storage`, `.env`, and source files are not directly web-accessible
- confirm the application is reachable at `/login`
- confirm the root URL redirects to login or dashboard

## 8. Functional smoke test

- sign in as an admin account
- confirm Terms, Instructors, Rooms, Catalog, Schedule, and Profile pages load
- confirm an active term can be selected
- confirm schedule grids render correctly
- confirm Readiness loads without errors
- confirm the registrar CSV downloads without locking the schedule
- confirm the CSV includes course-section, faculty, room/location, days, times, and term dates
- confirm historical token-protected downloads still work

## 9. Release checklist

Before calling the deployment final:

- clear caches after file upload
- rerun migrations after every schema change
- verify `APP_DEBUG=false`
- verify `AOP_ALLOW_SELF_REGISTRATION=false`
- verify historical schedule download URLs work from a browser
- verify the public HTTPS response sets Secure cookies and HSTS
- record the release ID, artifact checksum, database backup, previous release and smoke-test result
