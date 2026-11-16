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

## Explicit compatible-release selection

- Deployed successful artifact release `5ab8661bbdd3` with backup `/root/aop-backups/20261116T015156Z-pre-5ab8661bbdd3`.
- Used `scripts/select-release.sh` to select prior artifact release `c5e6d6a6de15`. HTTPS login, hashed asset, strict 37/37 reconciliation, SQLite integrity and foreign keys passed.
- Used the same command to select `5ab8661bbdd3` again. The complete validation set passed a second time.
- Neither selection ran migrations or restored a database; user-write storage remained shared throughout.

## Remaining recovery evidence

- Copy an encrypted backup to an operator-selected independent destination and prove retrieval.

## Isolated application recovery

- Extracted checksum-verified artifact `aop-5ab8661bbdd3.tar.gz` to `/root/aop-recovery-rehearsals/20261116T015400Z-5ab8661bbdd3`.
- Restored `.env` and SQLite from `/root/aop-backups/20261116T015156Z-pre-5ab8661bbdd3`, then rewrote only the copied environment's URL, database path, HTTPS enforcement and secure-cookie settings for loopback use.
- Copied shared storage and verified SHA-256 parity for all 14 durable files under `storage/app`; volatile framework cache/session/view/log files were not used as recovery evidence.
- Verified all 32 migration batches, strict 37/37 event reconciliation, SQLite integrity and foreign keys before starting the isolated server.
- Created a temporary administrator only in the copied database, then verified a real form/session login, an authenticated active-term Schedule Studio request and a registrar CSV attachment containing 34 rows.
- Stopped the loopback server after the checks. The retained rehearsal directory and its parent are mode 0700; `.env` and SQLite are mode 0600.

The source database SHA-256 was `8af25fcbfe3d78ad95659a51cf5d15a6ce339d4a091e278f1ac90fa2288beb3d`. The isolated database changed during the intentional login/session test and therefore is not expected to retain that hash.

## Scheduled verified local backup

- `scripts/create-backup.sh phase8-rehearsal` produced `/root/aop-backups/20261116T110116Z-phase8-rehearsal`; all files are mode 0600 and its manifest, SHA-256, SQLite integrity, foreign-key and storage-archive checks pass.
- Deployed artifact release `6d6594188cc3`, which preserves executable modes for repository operational scripts.
- Installed and enabled `aop-backup.timer`. Its sandboxed service can write only the backup/lock paths and reads application/runtime sources.
- The first systemd run produced `/root/aop-backups/20261116T110326Z-daily` for release `6d6594188cc3`; its manifest and all checksums pass.
- The timer runs daily at 2:15 AM Eastern with up to 15 minutes of randomized delay and persistent catch-up. No automatic deletion is enabled pending an approved retention and off-host policy.
