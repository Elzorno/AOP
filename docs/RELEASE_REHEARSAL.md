# Release and rollback rehearsal

Performed 16 November 2026 on the production host for `syllabi.elzorno.com`.

## Artifact deployment

- Built artifact `aop-c5e6d6a6de15.tar.gz` from a clean checkout using `scripts/build-release.sh`.
- Verified its SHA-256 sidecar and `RELEASE` manifest before deployment.
- Confirmed the artifact contains production Composer dependencies and compiled Vite assets, and excludes `.env`, SQLite data, `node_modules` and `storage`.
- Deployed with `scripts/deploy-release.sh`; the guarded migration reported nothing pending.
- The script created `/root/aop-backups/20261116T014905Z-pre-c5e6d6a6de15`, atomically selected `/var/www/aop-releases/c5e6d6a6de15`, reloaded PHP-FPM/Nginx, and passed HTTPS login, hashed-asset, strict reconciliation, SQLite integrity and foreign-key checks.

## Automatic failure rollback

- Built artifact `aop-a9d7fd7683a3.tar.gz` containing the guarded release selector and runbook.
- Deployed it with an intentional nonexistent HTTPS health path.
- Staging, backup, migration and cache preparation completed; the post-switch request returned HTTP 404 / curl status 22.
- The error trap automatically restored `/var/www/aop-current` to `/var/www/aop-releases/c5e6d6a6de15` and reloaded both services.
- The failed release remains at `/var/www/aop-releases/a9d7fd7683a3` for inspection. Its pre-attempt backup is `/root/aop-backups/20261116T015103Z-pre-a9d7fd7683a3`.
- After restoration, the public login returned HTTP 200, PHP-FPM and Nginx were active, SQLite integrity returned `ok`, foreign-key validation returned no rows, and strict reconciliation reported 37 sources / 37 events with zero discrepancies.

No database restore was performed. The rehearsal proves automatic code-pointer recovery after a failed post-switch health check; it does not claim that arbitrary destructive migrations can be rolled back.

## Remaining recovery evidence

- Rehearse explicit selection between two successful artifact-era releases with `scripts/select-release.sh`.
- Restore the application into an isolated location from artifact, `.env`, SQLite backup and shared stored files; verify login and representative schedule/export flows.
- Copy an encrypted backup to an operator-selected independent destination and prove retrieval.
