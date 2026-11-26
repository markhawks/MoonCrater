#!/usr/bin/env bash
set -euo pipefail
umask 077
export LC_ALL=C

die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die 'Run this script as root.'
[[ $# -eq 2 ]] || die 'Usage: update-rpm.sh <local RPM or GitHub release asset URL> <SHA-256>'
[[ $2 =~ ^[a-fA-F0-9]{64}$ ]] || die 'Provide the expected SHA-256 digest.'
source_arg=$1
expected_digest=${2,,}
config=/etc/mooncrater/apache-env.conf
backup_dir=/opt/mooncrater/var/backups
[[ -r $config ]] || die "Missing installed configuration: $config"
rpm -q mooncrater >/dev/null || die 'MoonCrater must already be installed via RPM.'
. /etc/os-release
[[ ${ID:-} == rhel && ${VERSION_ID%%.*} == 10 ]] || die 'RHEL 10 is required.'

exec 9>/run/mooncrater-rpm-update.lock
flock -n 9 || die 'Another MoonCrater RPM update is running.'

read_db_setting() {
    local key=$1
    awk -v wanted="$key" '$1 == "SetEnv" && $2 == wanted { print $3; exit }' "$config" | tr -d '"'
}
db_host=$(read_db_setting DB_HOST)
db_port=$(read_db_setting DB_PORT)
db_name=$(read_db_setting DB_NAME)
[[ $db_host == localhost || $db_host == 127.0.0.1 || $db_host == ::1 ]] || die 'This script requires local PostgreSQL.'
[[ $db_port =~ ^[0-9]{1,5}$ && $db_name =~ ^[a-z_][a-z0-9_]*$ ]] || die 'Invalid PostgreSQL port or database name.'

work_dir=$(mktemp -d /var/tmp/mooncrater-rpm-update.XXXXXX)
trap 'rm -f -- "$work_dir/mooncrater.rpm"; rmdir -- "$work_dir"' EXIT
pkg="$work_dir/mooncrater.rpm"
if [[ $source_arg == https://* ]]; then
    [[ $source_arg =~ ^https://github\.com/markhawks/MoonCrater/releases/download/v[0-9]+\.[0-9]+/mooncrater-[0-9]+\.[0-9]+-[0-9]+\.el10\.noarch\.rpm$ ]] ||
        die 'Use an official MoonCrater GitHub release asset URL.'
    curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --output "$pkg" -- "$source_arg"
else
    [[ -f $source_arg ]] || die 'RPM file not found.'
    cp -- "$source_arg" "$pkg"
fi

printf '%s  %s\n' "$expected_digest" "$pkg" | sha256sum -c -
name=$(rpm -qp --qf '%{NAME} %{ARCH}\n' "$pkg")
[[ $name == 'mooncrater noarch' ]] || die 'Expected a MoonCrater noarch RPM.'
target=$(rpm -qp --qf '%{VERSION}-%{RELEASE}.%{ARCH}' "$pkg")
current=$(rpm -q --qf '%{VERSION}-%{RELEASE}.%{ARCH}' mooncrater)
apachectl configtest
systemctl is-active --quiet httpd
systemctl is-active --quiet php-fpm

install -d -m 0750 "$backup_dir"
backup=$(mktemp "$backup_dir/mooncrater-${db_name}-pre-update.XXXXXX.dump")
if ! runuser -u postgres -- pg_dump --no-password --port="$db_port" --format=custom --dbname="$db_name" >"$backup" ||
   ! pg_restore --list "$backup" >/dev/null; then
    die "Backup failed. No update applied. Inspect $backup."
fi
printf 'Database backup: %s\n' "$backup"

if [[ $current != "$target" ]]; then
    dnf -y upgrade "$pkg"
fi
[[ $(rpm -q --qf '%{VERSION}-%{RELEASE}.%{ARCH}' mooncrater) == "$target" ]] ||
    die "DNF did not install the requested build $target."
php /opt/mooncrater/bin/migrate.php --apache-env
apachectl configtest
systemctl reload php-fpm httpd
systemctl is-active php-fpm
systemctl is-active httpd
printf 'MoonCrater %s ready. Database backup: %s\n' "$target" "$backup"
