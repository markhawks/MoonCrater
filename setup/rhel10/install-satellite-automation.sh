#!/usr/bin/env bash
set -euo pipefail

source_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
install_dir="${MOONCRATER_INSTALL_DIR:-/opt/mooncrater}"
[[ $EUID -eq 0 ]] || { echo 'Run this command as root.' >&2; exit 1; }
[[ $install_dir =~ ^/[A-Za-z0-9._/-]+$ && $install_dir != / ]] || { echo 'Invalid installation path.' >&2; exit 1; }

install -d -m 0750 "$install_dir/satellite-import-csv" "$install_dir/var"
sed "s|/opt/mooncrater|${install_dir}|g" \
    "$source_dir/setup/systemd/mooncrater-satellite-import.service" \
    > /etc/systemd/system/mooncrater-satellite-import.service
install -m 0644 "$source_dir/setup/systemd/mooncrater-satellite-import.timer" \
    /etc/systemd/system/mooncrater-satellite-import.timer
systemctl daemon-reload
systemctl enable --now mooncrater-satellite-import.timer

printf 'Automatic Satellite inbox check enabled (every five minutes).\n'
