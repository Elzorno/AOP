#!/usr/bin/env bash
set -euo pipefail

die() {
    echo "Backup refused: $*" >&2
    exit 65
}

require_absolute_path() {
    local name=$1
    local value=${!name:-}
    [[ -n "$value" ]] || die "$name is required"
    [[ "$value" == /* ]] || die "$name must be absolute"
    [[ "$value" != / && "$value" != /var && "$value" != /var/www && "$value" != /root && "$value" != /tmp ]] \
        || die "$name is too broad: $value"
}

[[ $# -eq 1 ]] || { echo "Usage: $0 LABEL" >&2; exit 64; }
[[ $EUID -eq 0 ]] || die "backup creation must run as root"
label=$1
[[ "$label" =~ ^[a-z0-9][a-z0-9._-]{0,47}$ ]] || die "label must be 1-48 lowercase safe characters"

for variable_name in AOP_DATABASE AOP_SHARED_ENV AOP_SHARED_STORAGE AOP_BACKUP_ROOT AOP_BACKUP_LOCK; do
    require_absolute_path "$variable_name"
done
for command_name in flock sha256sum sqlite3 tar; do
    command -v "$command_name" >/dev/null 2>&1 || die "required command is unavailable: $command_name"
done

[[ -f "$AOP_DATABASE" ]] || die "database does not exist: $AOP_DATABASE"
[[ -f "$AOP_SHARED_ENV" ]] || die "environment file does not exist: $AOP_SHARED_ENV"
[[ -d "$AOP_SHARED_STORAGE/app" ]] || die "durable storage directory does not exist: $AOP_SHARED_STORAGE/app"
[[ -d "$AOP_BACKUP_ROOT" ]] || die "backup root does not exist: $AOP_BACKUP_ROOT"
chmod 700 "$AOP_BACKUP_ROOT"

mkdir -p "$(dirname "$AOP_BACKUP_LOCK")"
exec 9>"$AOP_BACKUP_LOCK"
flock -n 9 || die "another backup holds $AOP_BACKUP_LOCK"

timestamp=$(date -u +%Y%m%dT%H%M%SZ)
final_directory="$AOP_BACKUP_ROOT/$timestamp-$label"
working_directory="$AOP_BACKUP_ROOT/.incomplete-$timestamp-$label-$$"
[[ ! -e "$final_directory" && ! -e "$working_directory" ]] || die "backup destination already exists"
mkdir -m 700 "$working_directory"

cleanup_incomplete() {
    if [[ -d "$working_directory" ]]; then
        case "$working_directory" in
            "$AOP_BACKUP_ROOT"/.incomplete-*) find "$working_directory" -depth -delete ;;
            *) echo "Refusing to clean unexpected path: $working_directory" >&2 ;;
        esac
    fi
}
trap cleanup_incomplete EXIT

sqlite3 "$AOP_DATABASE" ".backup '$working_directory/database.sqlite'"
cp --dereference "$AOP_SHARED_ENV" "$working_directory/.env"
tar -czf "$working_directory/storage-app.tar.gz" -C "$AOP_SHARED_STORAGE" app
chmod 600 "$working_directory/database.sqlite" "$working_directory/.env" "$working_directory/storage-app.tar.gz"

integrity_result=$(sqlite3 "$working_directory/database.sqlite" 'PRAGMA integrity_check;')
[[ "$integrity_result" == ok ]] || die "backup database integrity check failed"
foreign_key_result=$(sqlite3 "$working_directory/database.sqlite" 'PRAGMA foreign_key_check;')
[[ -z "$foreign_key_result" ]] || die "backup database foreign-key check failed"
tar -tzf "$working_directory/storage-app.tar.gz" >/dev/null

release_id=unknown
if [[ -n ${AOP_CURRENT_LINK:-} && -f ${AOP_CURRENT_LINK}/RELEASE ]]; then
    release_id=$(awk -F= '$1 == "release_id" { print $2 }' "${AOP_CURRENT_LINK}/RELEASE")
fi

(
    cd "$working_directory"
    sha256sum database.sqlite .env storage-app.tar.gz > SHA256SUMS
)
chmod 600 "$working_directory/SHA256SUMS"
printf '%s\n' \
    "created_at_utc=$timestamp" \
    "label=$label" \
    "release_id=$release_id" \
    "database_integrity=ok" \
    "foreign_key_violations=0" \
    "durable_storage_scope=storage/app" \
    > "$working_directory/MANIFEST"
chmod 600 "$working_directory/MANIFEST"

(
    cd "$working_directory"
    sha256sum --check SHA256SUMS >/dev/null
)

mv "$working_directory" "$final_directory"
trap - EXIT
echo "backup=$final_directory"
echo "release_id=$release_id"
