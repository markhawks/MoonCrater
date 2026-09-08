#!/usr/bin/env bash
set -euo pipefail

source_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
install_dir="${MOONCRATER_INSTALL_DIR:-/opt/mooncrater}"
import_user="${MOONCRATER_IMPORT_USER:-mooncrater-import}"
[[ $EUID -eq 0 ]] || { echo 'Run this command as root.' >&2; exit 1; }
[[ $install_dir =~ ^/[A-Za-z0-9._/-]+$ && $install_dir != / ]] || { echo 'Invalid installation path.' >&2; exit 1; }
[[ $import_user =~ ^[a-z_][a-z0-9_-]*$ ]] || { echo 'Invalid import username.' >&2; exit 1; }

if ! id "$import_user" >/dev/null 2>&1; then
    useradd --create-home --shell /bin/bash "$import_user"
fi
home_dir="$(getent passwd "$import_user" | cut -d: -f6)"
[[ -n $home_dir && -d $home_dir ]] || { echo "Home directory unavailable for $import_user." >&2; exit 1; }
install -d -m 0700 -o "$import_user" -g "$import_user" "$home_dir/.ssh"
install -d -m 0750 -o "$import_user" -g "$import_user" "$install_dir/satellite-import-csv"
install -d -m 0750 "$install_dir/var"
restorecon -RF "$home_dir/.ssh" "$install_dir/satellite-import-csv" >/dev/null 2>&1 || true

sed "s|/opt/mooncrater|${install_dir}|g" \
    "$source_dir/setup/systemd/mooncrater-satellite-import.service" \
    > /etc/systemd/system/mooncrater-satellite-import.service
install -m 0644 "$source_dir/setup/systemd/mooncrater-satellite-import.timer" \
    /etc/systemd/system/mooncrater-satellite-import.timer
systemctl daemon-reload
systemctl enable --now mooncrater-satellite-import.timer

printf 'Automatic Satellite inbox check enabled (every five minutes).\n'
printf 'Import account: %s (home: %s)\n' "$import_user" "$home_dir"
if [[ ! -s $home_dir/.ssh/authorized_keys ]]; then
    printf 'NEXT STEP: install the Satellite-generated public key with:\n'
    printf '  %s/setup/rhel10/configure-satellite-ingest.sh /path/to/public-key.pub\n' "$install_dir"
fi
