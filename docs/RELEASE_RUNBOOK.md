# AOP release and rollback runbook

This is the production path for `syllabi.elzorno.com`. Run builds as the unprivileged `aop-build` account and deploy/select operations as root. Never generate a new `APP_KEY`, seed production, or copy a database into a release directory.

## Build and inspect

```bash
sudo -u aop-build scripts/build-release.sh \
  /srv/aop-workspaces/modernization \
  /srv/aop-artifacts

sudo -u aop-build scripts/deploy-release.sh --verify-only \
  /srv/aop-artifacts/aop-<release>.tar.gz \
  /srv/aop-artifacts/aop-<release>.tar.gz.sha256
```

The checkout must be clean. Record the artifact checksum. The archive must contain `RELEASE`, `vendor/` and `public/build/`, and must not contain `.env`, SQLite data, `node_modules` or `storage`.

## Production variables

Use the same explicit values for deployment and release selection:

```bash
export AOP_RELEASES_DIR=/var/www/aop-releases
export AOP_CURRENT_LINK=/var/www/aop-current
export AOP_SHARED_ENV=/var/www/aop/.env
export AOP_SHARED_STORAGE=/var/www/aop/storage
export AOP_DATABASE=/var/www/aop/database/database.sqlite
export AOP_BACKUP_ROOT=/root/aop-backups
export AOP_DEPLOY_LOCK=/run/lock/aop-deploy.lock
export AOP_HEALTH_URL=https://syllabi.elzorno.com/login
export AOP_RELOAD_SERVICES='php8.3-fpm nginx'
```

## Deploy

```bash
scripts/deploy-release.sh \
  /srv/aop-artifacts/aop-<release>.tar.gz \
  /srv/aop-artifacts/aop-<release>.tar.gz.sha256
```

The script verifies the checksum/manifest, refuses an existing release ID, backs up SQLite and `.env`, links shared runtime state, runs guarded migrations and caches, atomically switches the pointer, reloads services, and checks HTTPS, the hashed asset, strict event reconciliation, SQLite integrity and foreign keys. A failed post-switch check restores the previous code pointer. It does not reverse migrations; migrations must remain backward-compatible through the rollback window.

Record the emitted release, previous release and backup paths. A failed attempt is deliberately retained for inspection and must not be silently overwritten.

## Select a compatible prior release

```bash
scripts/select-release.sh <12-character-release-id>
```

Selection is an atomic code-pointer operation and does not restore the database. The script requires an artifact-era `RELEASE` manifest, takes the deployment lock, reloads services and runs the same post-switch application/data checks. If validation fails it restores the starting pointer.

Use a database restore only for a separately approved data-recovery event. Restoring a pre-release database as routine code rollback would discard subsequent user work.

## Post-deployment record

Record:

- full commit and 12-character release ID;
- artifact SHA-256;
- backup path;
- previous release ID;
- migration output;
- HTTPS login/asset results;
- reconciliation, integrity and foreign-key results;
- operator and UTC timestamp.

## Consistent local backup

`create-backup.sh` captures a transaction-consistent SQLite copy, the environment, and durable `storage/app` content. It verifies SQLite integrity/foreign keys, the storage archive and SHA-256 checksums before atomically publishing the backup directory.

```bash
export AOP_BACKUP_LOCK=/run/lock/aop-backup.lock
scripts/create-backup.sh daily
```

It uses the other production path variables from this runbook and records the selected artifact release when `AOP_CURRENT_LINK` is set. Framework sessions, caches, compiled views and logs are deliberately excluded. Local backup success is not disaster recovery until an encrypted copy is retrieved from an independent operator-selected destination.
