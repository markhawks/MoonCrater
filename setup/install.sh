#!/usr/bin/env bash
set -euo pipefail

source_dir="$(cd "$(dirname "$0")/.." && pwd)"
install_dir="${MOONCRATER_INSTALL_DIR:-/opt/mooncrater}"
if [[ $EUID -ne 0 ]]; then echo 'Run this installer as root.' >&2; exit 1; fi

install -d -m 0755 "$install_dir"
for item in app bin migrations public setup tests docs README.md CHANGELOG.md SECURITY.md CONTRIBUTING.md LICENSE .env.example; do
    cp -a "$source_dir/$item" "$install_dir/"
done
install -d -m 0750 /etc/mooncrater "$install_dir/var/backups" "$install_dir/var/uploads" "$install_dir/satellite-import-csv"
echo "Files installed in $install_dir"
echo 'Next: configure Apache/DB, run bin/migrate.php, then bin/create-admin.php USERNAME.'
