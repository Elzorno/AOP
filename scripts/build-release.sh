#!/usr/bin/env bash
set -euo pipefail

usage() {
    echo "Usage: $0 SOURCE_CHECKOUT OUTPUT_DIRECTORY" >&2
}

if [[ $# -ne 2 ]]; then
    usage
    exit 64
fi

source_checkout=$(realpath "$1")
output_directory=$(realpath -m "$2")

if [[ ! -d "$source_checkout/.git" ]]; then
    echo "Source is not a Git checkout: $source_checkout" >&2
    exit 65
fi

if [[ -n $(git -C "$source_checkout" status --porcelain) ]]; then
    echo "Source checkout must be clean before building a release." >&2
    exit 66
fi

for command_name in git composer npm tar gzip sha256sum php; do
    if ! command -v "$command_name" >/dev/null 2>&1; then
        echo "Required command is unavailable: $command_name" >&2
        exit 69
    fi
done

release_commit=$(git -C "$source_checkout" rev-parse HEAD)
release_id=$(git -C "$source_checkout" rev-parse --short=12 HEAD)
source_epoch=$(git -C "$source_checkout" show -s --format=%ct HEAD)
artifact_name="aop-${release_id}.tar.gz"

mkdir -p "$output_directory"
if [[ -e "$output_directory/$artifact_name" || -e "$output_directory/$artifact_name.sha256" ]]; then
    echo "Release artifact already exists: $output_directory/$artifact_name" >&2
    exit 73
fi

temporary_root=$(mktemp -d "${TMPDIR:-/tmp}/aop-release-build.XXXXXX")
cleanup() {
    case "$temporary_root" in
        "${TMPDIR:-/tmp}"/aop-release-build.*) find "$temporary_root" -depth -delete ;;
        *) echo "Refusing to remove unexpected temporary path: $temporary_root" >&2 ;;
    esac
}
trap cleanup EXIT

stage_directory="$temporary_root/aop"
mkdir -p "$stage_directory"
git -C "$source_checkout" archive "$release_commit" | tar -x -C "$stage_directory"
install -m 600 /dev/null "$stage_directory/database/database.sqlite"

(
    cd "$stage_directory"
    composer install \
        --no-dev \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader \
        --classmap-authoritative
    npm ci --ignore-scripts
    npm run build
)

unlink "$stage_directory/database/database.sqlite"

cat > "$stage_directory/RELEASE" <<EOF
release_id=$release_id
commit=$release_commit
source_date_epoch=$source_epoch
composer_lock_sha256=$(sha256sum "$stage_directory/composer.lock" | cut -d' ' -f1)
package_lock_sha256=$(sha256sum "$stage_directory/package-lock.json" | cut -d' ' -f1)
EOF

artifact_path="$output_directory/$artifact_name"
(
    cd "$stage_directory"
    tar \
        --sort=name \
        --mtime="@$source_epoch" \
        --owner=0 \
        --group=0 \
        --numeric-owner \
        --exclude='./database/*.sqlite*' \
        --exclude='./node_modules' \
        -cf - . | gzip -n > "$artifact_path"
)

(
    cd "$output_directory"
    sha256sum "$artifact_name" > "$artifact_name.sha256"
)

echo "release_id=$release_id"
echo "artifact=$artifact_path"
echo "checksum=$artifact_path.sha256"
