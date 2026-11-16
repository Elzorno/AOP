#!/usr/bin/env bash
set -euo pipefail

usage() {
    cat >&2 <<'EOF'
Usage:
  deploy-release.sh --verify-only ARTIFACT CHECKSUM
  deploy-release.sh ARTIFACT CHECKSUM

Deployment mode requires these absolute-path environment variables:
  AOP_RELEASES_DIR AOP_CURRENT_LINK AOP_SHARED_ENV AOP_SHARED_STORAGE
  AOP_DATABASE AOP_BACKUP_ROOT AOP_DEPLOY_LOCK AOP_HEALTH_URL
and a space-separated AOP_RELOAD_SERVICES list.
EOF
}

die() {
    echo "Deployment refused: $*" >&2
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

verify_artifact() {
    local artifact=$1
    local checksum=$2
    [[ -f "$artifact" ]] || die "artifact does not exist: $artifact"
    [[ -f "$checksum" ]] || die "checksum does not exist: $checksum"
    [[ $(dirname "$artifact") == $(dirname "$checksum") ]] \
        || die "artifact and checksum must be in the same directory"

    (
        cd "$(dirname "$artifact")"
        sha256sum --check "$(basename "$checksum")"
    )

    local release_manifest
    release_manifest=$(tar -xOf "$artifact" ./RELEASE) || die "artifact has no RELEASE manifest"
    release_id=$(awk -F= '$1 == "release_id" { print $2 }' <<<"$release_manifest")
    release_commit=$(awk -F= '$1 == "commit" { print $2 }' <<<"$release_manifest")
    [[ "$release_id" =~ ^[0-9a-f]{12}$ ]] || die "invalid release ID in manifest"
    [[ "$release_commit" =~ ^[0-9a-f]{40}$ ]] || die "invalid commit in manifest"
    [[ "$release_commit" == "$release_id"* ]] || die "release ID does not match commit"

    if tar -tzf "$artifact" | grep -Eq '^\./(\.env$|database/.*\.(sqlite|db)|node_modules/|storage(/|$))'; then
        die "artifact contains runtime data or an excluded dependency directory"
    fi
}

if [[ ${1:-} == --verify-only ]]; then
    [[ $# -eq 3 ]] || { usage; exit 64; }
    artifact=$(realpath "$2")
    checksum=$(realpath "$3")
    verify_artifact "$artifact" "$checksum"
    echo "verified_release=$release_id"
    echo "verified_commit=$release_commit"
    exit 0
fi

[[ $# -eq 2 ]] || { usage; exit 64; }
[[ $EUID -eq 0 ]] || die "deployment mode must run as root"

artifact=$(realpath "$1")
checksum=$(realpath "$2")
verify_artifact "$artifact" "$checksum"

for variable_name in \
    AOP_RELEASES_DIR AOP_CURRENT_LINK AOP_SHARED_ENV AOP_SHARED_STORAGE \
    AOP_DATABASE AOP_BACKUP_ROOT AOP_DEPLOY_LOCK; do
    require_absolute_path "$variable_name"
done
[[ ${AOP_HEALTH_URL:-} == https://* ]] || die "AOP_HEALTH_URL must use HTTPS"
[[ -n ${AOP_RELOAD_SERVICES:-} ]] || die "AOP_RELOAD_SERVICES is required"

for command_name in curl flock php readlink sha256sum sqlite3 systemctl tar; do
    command -v "$command_name" >/dev/null 2>&1 || die "required command is unavailable: $command_name"
done

[[ -d "$AOP_RELEASES_DIR" ]] || die "release directory does not exist: $AOP_RELEASES_DIR"
[[ -f "$AOP_SHARED_ENV" ]] || die "shared environment does not exist: $AOP_SHARED_ENV"
[[ -d "$AOP_SHARED_STORAGE" ]] || die "shared storage does not exist: $AOP_SHARED_STORAGE"
[[ -f "$AOP_DATABASE" ]] || die "database does not exist: $AOP_DATABASE"
[[ -d "$AOP_BACKUP_ROOT" ]] || die "backup root does not exist: $AOP_BACKUP_ROOT"
[[ -L "$AOP_CURRENT_LINK" ]] || die "current release pointer is not a symlink: $AOP_CURRENT_LINK"

for service_name in $AOP_RELOAD_SERVICES; do
    [[ "$service_name" =~ ^[a-zA-Z0-9@_.-]+$ ]] || die "invalid service name: $service_name"
    systemctl is-active --quiet "$service_name" || die "service is not active: $service_name"
done

mkdir -p "$(dirname "$AOP_DEPLOY_LOCK")"
exec 9>"$AOP_DEPLOY_LOCK"
flock -n 9 || die "another deployment holds $AOP_DEPLOY_LOCK"

release_directory="$AOP_RELEASES_DIR/$release_id"
[[ ! -e "$release_directory" ]] || die "release directory already exists: $release_directory"
previous_release=$(readlink -f "$AOP_CURRENT_LINK")
[[ -d "$previous_release" ]] || die "current release target is unavailable: $previous_release"

switch_candidate="${AOP_CURRENT_LINK}.candidate.$$"
switched=0
cleanup_candidate() {
    if [[ -L "$switch_candidate" ]]; then
        unlink "$switch_candidate"
    fi
}
rollback_pointer() {
    local failure_status=$?
    cleanup_candidate
    if [[ $switched -eq 1 ]]; then
        echo "Smoke check failed; restoring code pointer to $previous_release" >&2
        ln -s "$previous_release" "$switch_candidate"
        mv -Tf "$switch_candidate" "$AOP_CURRENT_LINK"
        for service_name in $AOP_RELOAD_SERVICES; do
            systemctl reload "$service_name" || true
        done
    fi
    exit "$failure_status"
}
trap rollback_pointer ERR
trap cleanup_candidate EXIT

mkdir "$release_directory"
tar -xzf "$artifact" -C "$release_directory"
ln -s "$AOP_SHARED_ENV" "$release_directory/.env"
ln -s "$AOP_SHARED_STORAGE" "$release_directory/storage"

chown -R root:root "$release_directory"
find "$release_directory" -type d -exec chmod 755 {} +
find "$release_directory" -type f -exec chmod 644 {} +
chmod 755 "$release_directory/artisan"

backup_directory="$AOP_BACKUP_ROOT/$(date -u +%Y%m%dT%H%M%SZ)-pre-$release_id"
mkdir "$backup_directory"
sqlite3 "$AOP_DATABASE" ".backup '$backup_directory/database.sqlite'"
cp --dereference "$AOP_SHARED_ENV" "$backup_directory/.env"
cp "$checksum" "$backup_directory/$(basename "$checksum")"
cp "$release_directory/RELEASE" "$backup_directory/RELEASE"
chmod 600 "$backup_directory/database.sqlite" "$backup_directory/.env"

(
    cd "$release_directory"
    AOP_EXPECTED_DATABASE="$AOP_DATABASE" php artisan migrate --force
    php artisan optimize:clear
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
)

ln -s "$release_directory" "$switch_candidate"
mv -Tf "$switch_candidate" "$AOP_CURRENT_LINK"
switched=1
for service_name in $AOP_RELOAD_SERVICES; do
    systemctl reload "$service_name"
done

curl --fail --silent --show-error --output /dev/null "$AOP_HEALTH_URL"
(
    cd "$AOP_CURRENT_LINK"
    php artisan aop:sync-scheduled-events --fail-on-drift --no-interaction
)
health_origin=$(php -r '$parts=parse_url($argv[1]); echo $parts["scheme"]."://".$parts["host"].(isset($parts["port"]) ? ":".$parts["port"] : "");' "$AOP_HEALTH_URL")
asset_file=$(php -r '$manifest=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); echo $manifest["resources/css/app.css"]["file"];' "$AOP_CURRENT_LINK/public/build/manifest.json")
curl --fail --silent --show-error --output /dev/null "$health_origin/build/$asset_file"
integrity_result=$(sqlite3 "$AOP_DATABASE" 'PRAGMA integrity_check;')
[[ "$integrity_result" == ok ]]
foreign_key_result=$(sqlite3 "$AOP_DATABASE" 'PRAGMA foreign_key_check;')
[[ -z "$foreign_key_result" ]]

switched=0
trap - ERR
echo "deployed_release=$release_id"
echo "previous_release=$previous_release"
echo "backup=$backup_directory"
