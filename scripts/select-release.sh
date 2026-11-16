#!/usr/bin/env bash
set -euo pipefail

die() {
    echo "Release selection refused: $*" >&2
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

[[ $# -eq 1 ]] || { echo "Usage: $0 RELEASE_ID" >&2; exit 64; }
[[ $EUID -eq 0 ]] || die "release selection must run as root"
release_id=$1
[[ "$release_id" =~ ^[0-9a-f]{12}$ ]] || die "release ID must be 12 lowercase hexadecimal characters"

for variable_name in AOP_RELEASES_DIR AOP_CURRENT_LINK AOP_DATABASE AOP_DEPLOY_LOCK; do
    require_absolute_path "$variable_name"
done
[[ ${AOP_HEALTH_URL:-} == https://* ]] || die "AOP_HEALTH_URL must use HTTPS"
[[ -n ${AOP_RELOAD_SERVICES:-} ]] || die "AOP_RELOAD_SERVICES is required"

for command_name in curl flock php readlink sqlite3 systemctl; do
    command -v "$command_name" >/dev/null 2>&1 || die "required command is unavailable: $command_name"
done

target_release="$AOP_RELEASES_DIR/$release_id"
[[ -d "$target_release" ]] || die "target release does not exist: $target_release"
[[ $(realpath "$target_release") == "$target_release" ]] || die "target release must be a real directory under the release root"
[[ -f "$target_release/RELEASE" ]] || die "target has no RELEASE manifest"
grep -qx "release_id=$release_id" "$target_release/RELEASE" || die "target manifest does not match release ID"
[[ -L "$AOP_CURRENT_LINK" ]] || die "current release pointer is not a symlink"
[[ -f "$AOP_DATABASE" ]] || die "database does not exist: $AOP_DATABASE"

for service_name in $AOP_RELOAD_SERVICES; do
    [[ "$service_name" =~ ^[a-zA-Z0-9@_.-]+$ ]] || die "invalid service name: $service_name"
    systemctl is-active --quiet "$service_name" || die "service is not active: $service_name"
done

mkdir -p "$(dirname "$AOP_DEPLOY_LOCK")"
exec 9>"$AOP_DEPLOY_LOCK"
flock -n 9 || die "another deployment holds $AOP_DEPLOY_LOCK"

previous_release=$(readlink -f "$AOP_CURRENT_LINK")
[[ -d "$previous_release" ]] || die "current release target is unavailable: $previous_release"
[[ "$previous_release" != "$target_release" ]] || die "requested release is already selected"

switch_candidate="${AOP_CURRENT_LINK}.candidate.$$"
switched=0
cleanup_candidate() {
    if [[ -L "$switch_candidate" ]]; then
        unlink "$switch_candidate"
    fi
}
restore_previous() {
    local failure_status=$?
    cleanup_candidate
    if [[ $switched -eq 1 ]]; then
        echo "Release validation failed; restoring $previous_release" >&2
        ln -s "$previous_release" "$switch_candidate"
        mv -Tf "$switch_candidate" "$AOP_CURRENT_LINK"
        for service_name in $AOP_RELOAD_SERVICES; do
            systemctl reload "$service_name" || true
        done
    fi
    exit "$failure_status"
}
trap restore_previous ERR
trap cleanup_candidate EXIT

ln -s "$target_release" "$switch_candidate"
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
[[ $(sqlite3 "$AOP_DATABASE" 'PRAGMA integrity_check;') == ok ]]
[[ -z $(sqlite3 "$AOP_DATABASE" 'PRAGMA foreign_key_check;') ]]

switched=0
trap - ERR
echo "selected_release=$release_id"
echo "previous_release=$previous_release"
