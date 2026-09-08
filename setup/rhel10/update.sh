#!/usr/bin/env bash
set -euo pipefail

# Safe application update for an existing MoonCreater installation on RHEL 10.
# The database, credentials and runtime data are preserved.

source_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
install_dir="${MOONCREATER_INSTALL_DIR:-/opt/mooncreater}"
env_file="${MOONCREATER_ENV_FILE:-/etc/mooncreater/apache-env.conf}"
backup_dir="${MOONCREATER_BACKUP_DIR:-${install_dir}/var/backups}"
health_url="${MOONCREATER_HEALTH_URL:-http://127.0.0.1/}"
allow_dirty="${MOONCREATER_ALLOW_DIRTY_SOURCE:-no}"
stage_dir=''
previous_dir=''
files_swapped=no

die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
info() { printf '\n==> %s\n' "$*"; }

cleanup() {
    if [[ -n $stage_dir && -d $stage_dir ]]; then
        rm -rf -- "$stage_dir"
    fi
}

finish() {
    local exit_code=$?
    trap - EXIT INT TERM
    if [[ $exit_code -ne 0 && $files_swapped == yes && -n $previous_dir && -d $previous_dir ]]; then
        printf '\nUpdate failed; restoring the previous application files.\n' >&2
        if [[ -d $install_dir ]]; then
            failed_dir="${install_dir}.failed.$(date -u +%Y%m%dT%H%M%SZ)"
            mv -- "$install_dir" "$failed_dir"
        fi
        mv -- "$previous_dir" "$install_dir"
        restorecon -RF "$install_dir/public" >/dev/null 2>&1 || true
        systemctl reload httpd >/dev/null 2>&1 || true
        printf 'Previous files restored. The PostgreSQL backup was not modified.\n' >&2
    fi
    cleanup
    exit "$exit_code"
}

read_apache_env() {
    local wanted=$1 line value
    while IFS= read -r line; do
        if [[ $line =~ ^[[:space:]]*SetEnv[[:space:]]+${wanted}[[:space:]]+(.+)[[:space:]]*$ ]]; then
            value=${BASH_REMATCH[1]}
            value=${value#\"}
            value=${value%\"}
            printf '%s' "$value"
            return 0
        fi
    done < "$env_file"
    return 1
}

trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

[[ $EUID -eq 0 ]] || die 'Run this updater as root.'
[[ -r /etc/os-release ]] || die 'Cannot identify the operating system.'
. /etc/os-release
[[ ${ID:-} == rhel && ${VERSION_ID%%.*} == 10 ]] || \
    die "This updater supports RHEL 10 only (detected ${PRETTY_NAME:-unknown})."
[[ $install_dir =~ ^/[A-Za-z0-9._/-]+$ && $install_dir != / ]] || die 'Invalid installation path.'
[[ $backup_dir =~ ^/[A-Za-z0-9._/-]+$ && $backup_dir != / ]] || die 'Invalid backup path.'
[[ $source_dir != "$install_dir" ]] || die 'Run the updater from the Git clone, not from /opt/mooncreater.'
[[ -d $install_dir/public && -f $install_dir/app/version.php ]] || die 'Existing MoonCreater installation not found.'
[[ -r $env_file ]] || die "Configuration file not readable: ${env_file}"
[[ $allow_dirty == yes || $allow_dirty == no ]] || die 'MOONCREATER_ALLOW_DIRTY_SOURCE must be yes or no.'

for command in php psql pg_dump apachectl systemctl curl flock git; do
    command -v "$command" >/dev/null || die "Missing required command: ${command}"
done

exec 9>/run/mooncreater-update.lock
flock -n 9 || die 'Another MoonCreater update is already running.'

if [[ -d $source_dir/.git && $allow_dirty != yes ]]; then
    [[ -z $(git -C "$source_dir" status --porcelain) ]] || \
        die 'The source Git checkout has uncommitted changes. Commit them or set MOONCREATER_ALLOW_DIRTY_SOURCE=yes.'
fi

db_host="$(read_apache_env DB_HOST)" || die 'DB_HOST is missing from the Apache environment file.'
db_port="$(read_apache_env DB_PORT)" || die 'DB_PORT is missing from the Apache environment file.'
db_name="$(read_apache_env DB_NAME)" || die 'DB_NAME is missing from the Apache environment file.'
db_user="$(read_apache_env DB_USER)" || die 'DB_USER is missing from the Apache environment file.'
db_pass="$(read_apache_env DB_PASS)" || die 'DB_PASS is missing from the Apache environment file.'

current_version="$(php -r 'require $argv[1]; echo APP_VERSION;' "$install_dir/app/version.php")"
new_version="$(php -r 'require $argv[1]; echo APP_VERSION;' "$source_dir/app/version.php")"
info "Updating MoonCreater ${current_version} to ${new_version}"

info 'Checking the source release'
"$source_dir/setup/check.sh"

info 'Creating a PostgreSQL backup'
install -d -m 0750 "$backup_dir"
backup_file="${backup_dir}/mooncreater-${db_name}-$(date -u +%Y%m%dT%H%M%SZ)-pre-${new_version}.dump"
PGPASSWORD="$db_pass" pg_dump \
    --host="$db_host" --port="$db_port" --username="$db_user" \
    --format=custom --file="$backup_file" "$db_name"
chmod 0600 "$backup_file"
printf 'Backup: %s\n' "$backup_file"

info 'Preparing the new application release'
install_parent="$(dirname "$install_dir")"
stage_dir="$(mktemp -d "${install_parent}/.mooncreater-update.XXXXXX")"
chmod 0755 "$stage_dir"
for item in app bin migrations public setup tests docs README.md SECURITY.md CONTRIBUTING.md LICENSE .env.example; do
    [[ -e $source_dir/$item ]] || die "Release item missing: ${item}"
    cp -a "$source_dir/$item" "$stage_dir/"
done
if [[ -f $source_dir/CHANGELOG.md ]]; then
    cp -a "$source_dir/CHANGELOG.md" "$stage_dir/"
fi
if [[ -d $install_dir/var ]]; then
    cp -a "$install_dir/var" "$stage_dir/"
else
    install -d -m 0750 "$stage_dir/var/backups" "$stage_dir/var/uploads"
fi

info 'Applying database migrations'
export DB_HOST="$db_host" DB_PORT="$db_port" DB_NAME="$db_name" DB_USER="$db_user" DB_PASS="$db_pass"
export APP_TIMEZONE="${APP_TIMEZONE:-Europe/Rome}"
php "$stage_dir/bin/migrate.php"

info 'Checking the staged release'
"$stage_dir/setup/check.sh"
apachectl configtest

info 'Activating the new release'
previous_dir="${install_dir}.previous.$(date -u +%Y%m%dT%H%M%SZ)"
mv -- "$install_dir" "$previous_dir"
files_swapped=yes
mv -- "$stage_dir" "$install_dir"
stage_dir=''
restorecon -RF "$install_dir/public"
systemctl reload httpd

http_code="$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' \
    --max-time 10 "$health_url")"
[[ $http_code == 200 || $http_code == 302 ]] || die "HTTP health check failed with status ${http_code}."

files_swapped=no
rm -rf -- "$previous_dir"
previous_dir=''
unset DB_PASS db_pass PGPASSWORD

printf '\nMoonCreater update completed successfully.\n'
printf 'Version: %s\n' "$new_version"
printf 'Database backup: %s\n' "$backup_file"
printf 'HTTP check: %s returned %s\n' "$health_url" "$http_code"
