#!/usr/bin/env bash
set -euo pipefail

# One-time migration from the misspelled MoonCreater filesystem layout.
old_install=/opt/mooncreater
new_install=/opt/mooncrater
old_config=/etc/mooncreater
new_config=/etc/mooncrater
old_vhost=/etc/httpd/conf.d/mooncreater.conf
new_vhost=/etc/httpd/conf.d/mooncrater.conf
saved_vhost=/etc/httpd/conf.d/mooncreater.conf.pre-mooncrater
temporary_vhost=/etc/httpd/conf.d/.mooncrater.conf.tmp
opt_moved=no
config_moved=no
vhost_swapped=no
completed=no

die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
info() { printf '\n==> %s\n' "$*"; }

finish() {
    local exit_code=$?
    trap - EXIT INT TERM
    rm -f -- "$temporary_vhost"
    if [[ $exit_code -ne 0 && $completed == no ]]; then
        printf '\nLayout migration failed; restoring legacy paths.\n' >&2
        if [[ $vhost_swapped == yes ]]; then
            rm -f -- "$new_vhost"
            [[ ! -e $old_vhost && -e $saved_vhost ]] && mv -- "$saved_vhost" "$old_vhost"
        fi
        if [[ $config_moved == yes && -d $new_config && ! -e $old_config ]]; then
            mv -- "$new_config" "$old_config"
        fi
        if [[ $opt_moved == yes && -d $new_install && ! -e $old_install ]]; then
            mv -- "$new_install" "$old_install"
        fi
        restorecon -RF "$old_install/public" >/dev/null 2>&1 || true
        systemctl reload httpd >/dev/null 2>&1 || true
    fi
    exit "$exit_code"
}

trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

[[ $EUID -eq 0 ]] || die 'Run this migration as root.'
if [[ ! -d $old_install && -d $new_install ]]; then
    echo 'MoonCrater filesystem layout is already current.'
    completed=yes
    exit 0
fi
[[ -d $old_install ]] || die "Legacy installation not found: ${old_install}"
[[ ! -e $new_install ]] || die "Target already exists: ${new_install}"
[[ -d $old_config ]] || die "Legacy configuration not found: ${old_config}"
[[ ! -e $new_config ]] || die "Target already exists: ${new_config}"
[[ -f $old_vhost ]] || die "Legacy Apache virtual host not found: ${old_vhost}"
[[ ! -e $new_vhost ]] || die "Target already exists: ${new_vhost}"
[[ ! -e $saved_vhost ]] || die "Safety copy already exists: ${saved_vhost}"

for command in apachectl systemctl semanage restorecon flock curl; do
    command -v "$command" >/dev/null || die "Missing required command: ${command}"
done
exec 8>/run/mooncrater-layout-migration.lock
flock -n 8 || die 'Another MoonCrater layout migration is already running.'

info 'Preparing the corrected Apache virtual host'
sed \
    -e 's|/opt/mooncreater|/opt/mooncrater|g' \
    -e 's|/etc/mooncreater|/etc/mooncrater|g' \
    -e 's|mooncreater-error\.log|mooncrater-error.log|g' \
    -e 's|mooncreater-access\.log|mooncrater-access.log|g' \
    "$old_vhost" > "$temporary_vhost"
chmod 0644 "$temporary_vhost"

info 'Renaming the application and configuration directories'
mv -- "$old_install" "$new_install"
opt_moved=yes
mv -- "$old_config" "$new_config"
config_moved=yes
sed -i 's/^SetEnv APP_NAME MoonCreater$/SetEnv APP_NAME MoonCrater/' "$new_config/apache-env.conf"

mv -- "$old_vhost" "$saved_vhost"
vhost_swapped=yes
mv -- "$temporary_vhost" "$new_vhost"

info 'Updating SELinux labels and validating Apache'
semanage fcontext -a -t httpd_sys_content_t '/opt/mooncrater/public(/.*)?' 2>/dev/null || \
    semanage fcontext -m -t httpd_sys_content_t '/opt/mooncrater/public(/.*)?'
restorecon -RF "$new_install/public"
apachectl configtest
systemctl reload httpd

http_code="$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' \
    --max-time 10 http://127.0.0.1/)"
[[ $http_code == 200 || $http_code == 302 ]] || die "HTTP health check failed with status ${http_code}."

semanage fcontext -d '/opt/mooncreater/public(/.*)?' 2>/dev/null || true
completed=yes
printf '\nMoonCrater filesystem layout migration completed.\n'
printf 'Application: %s\nConfiguration: %s\nHTTP status: %s\n' "$new_install" "$new_config" "$http_code"
