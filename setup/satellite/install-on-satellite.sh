#!/usr/bin/env bash
set -euo pipefail

# Copy this installer together with export-and-send.sh to the Red Hat Satellite server, then run it as root.
source_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
install_dir="${MOONCRATER_SATELLITE_SCRIPT_DIR:-/root/mooncrater-script}"
destination_host="${MOONCRATER_HOST:-}"
destination_user="${MOONCRATER_SSH_USER:-mooncrater-import}"
destination_inbox="${MOONCRATER_INBOX:-/opt/mooncrater/satellite-import-csv}"
ssh_key="${MOONCRATER_SSH_KEY:-/root/.ssh/mooncrater_export_ed25519}"
config_file="/etc/mooncrater-satellite-export.conf"
cron_file="/etc/cron.d/mooncrater-satellite-export"
log_file="/var/log/mooncrater-satellite-export.log"

die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
[[ $EUID -eq 0 ]] || die 'Run this installer as root.'
[[ -r $source_dir/export-and-send.sh ]] || die "export-and-send.sh must be next to this installer."
if [[ -z $destination_host ]]; then
    read -r -p 'MoonCrater server hostname or IP address: ' destination_host
fi
[[ $install_dir =~ ^/root/[A-Za-z0-9._/-]+$ ]] || die 'The script directory must be below /root.'
[[ $destination_host =~ ^[A-Za-z0-9][A-Za-z0-9.-]*$ ]] || die 'Invalid MoonCrater hostname or IPv4 address.'
[[ $destination_user =~ ^[a-z_][a-z0-9_-]*$ ]] || die 'Invalid SSH username.'
[[ $destination_inbox =~ ^/[A-Za-z0-9._/-]+$ ]] || die 'Invalid MoonCrater inbox path.'
[[ $ssh_key =~ ^/[A-Za-z0-9._/-]+$ ]] || die 'Invalid SSH key path.'

for command in dnf systemctl install ssh-keygen ssh-keyscan; do
    command -v "$command" >/dev/null || die "Missing required command: $command"
done

printf 'Installing cron and OpenSSH clients...\n'
dnf install -y cronie openssh-clients
systemctl enable --now crond

install -d -m 0700 "$install_dir" "$(dirname "$ssh_key")"
install -m 0700 "$source_dir/export-and-send.sh" "$install_dir/export_satellite_completo.sh"

if [[ ! -f $ssh_key ]]; then
    ssh-keygen -q -t ed25519 -N '' -C "mooncrater-satellite-export@$(hostname -f)" -f "$ssh_key"
fi
chmod 0600 "$ssh_key"
chmod 0644 "${ssh_key}.pub"

known_hosts="$(dirname "$ssh_key")/known_hosts"
touch "$known_hosts"
chmod 0600 "$known_hosts"
if ! ssh-keygen -F "$destination_host" -f "$known_hosts" >/dev/null; then
    ssh-keyscan -H "$destination_host" >> "$known_hosts" 2>/dev/null || \
        die "Could not retrieve the SSH host key from ${destination_host}."
fi

umask 0077
{
    printf 'MOONCRATER_HOST=%q\n' "$destination_host"
    printf 'MOONCRATER_SSH_USER=%q\n' "$destination_user"
    printf 'MOONCRATER_INBOX=%q\n' "$destination_inbox"
    printf 'MOONCRATER_SSH_KEY=%q\n' "$ssh_key"
} > "$config_file"
chmod 0600 "$config_file"

touch "$log_file"
chmod 0600 "$log_file"
cat > "$cron_file" <<EOF
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

0 2 * * * root $install_dir/export_satellite_completo.sh >>$log_file 2>&1
EOF
chmod 0644 "$cron_file"

printf '\nSatellite exporter installed.\n'
printf 'Script: %s/export_satellite_completo.sh\n' "$install_dir"
printf 'Schedule: every day at 02:00\n'
printf 'Log: %s\n' "$log_file"
printf 'SSH public key to authorize on MoonCrater:\n\n'
cat "${ssh_key}.pub"
printf '\nRecorded MoonCrater SSH host-key fingerprint (verify it with the server administrator):\n'
ssh-keygen -lf "$known_hosts"
printf '\nOn the MoonCrater server, save this public key and run:\n'
printf '  sudo /opt/mooncrater/setup/rhel10/configure-satellite-ingest.sh /path/to/mooncrater_export_ed25519.pub\n'
printf '\nAfter authorizing the key, test from Satellite with:\n'
printf '  %s/export_satellite_completo.sh\n' "$install_dir"
