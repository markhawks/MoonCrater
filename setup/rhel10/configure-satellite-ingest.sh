#!/usr/bin/env bash
set -euo pipefail

install_dir="${MOONCRATER_INSTALL_DIR:-/opt/mooncrater}"
public_key_file="${1:-}"
import_user="${MOONCRATER_IMPORT_USER:-mooncrater-import}"

die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
[[ $EUID -eq 0 ]] || die 'Run this command as root.'
[[ $install_dir =~ ^/[A-Za-z0-9._/-]+$ && $install_dir != / ]] || die 'Invalid installation path.'
[[ $import_user =~ ^[a-z_][a-z0-9_-]*$ ]] || die 'Invalid import username.'
[[ -n $public_key_file && -r $public_key_file ]] || \
    die 'Usage: configure-satellite-ingest.sh /path/to/satellite-public-key.pub'
grep -Eq '^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(256|384|521))[[:space:]]' "$public_key_file" || die 'Invalid SSH public key.'

if ! id "$import_user" >/dev/null 2>&1; then
    useradd --create-home --shell /bin/bash "$import_user"
fi
home_dir="$(getent passwd "$import_user" | cut -d: -f6)"
install -d -m 0700 -o "$import_user" -g "$import_user" "$home_dir/.ssh"
install -m 0600 -o "$import_user" -g "$import_user" "$public_key_file" "$home_dir/.ssh/authorized_keys"
install -d -m 0750 -o "$import_user" -g "$import_user" "$install_dir/satellite-import-csv"
restorecon -RF "$home_dir/.ssh" "$install_dir/satellite-import-csv" >/dev/null 2>&1 || true

printf 'Satellite ingestion enabled for %s. Inbox: %s/satellite-import-csv\n' "$import_user" "$install_dir"
