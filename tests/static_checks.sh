#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

for file in public/*.php app/*.php app/Domain/*.php app/View/*.php bin/*.php; do php -l "$file" >/dev/null; done

if grep -R -n -E --include='*.php' "define\(['\"]DB_(PASS|PASSWORD)['\"]" app public bin; then
    echo "Hard-coded credential found" >&2
    exit 1
fi

for removed in add_admin.php add_user.php add_users.php; do
    test ! -e "public/$removed"
done

for endpoint in action.php action_infra.php action_zabbix.php backup_db.php import_ivanti.php import_satellite_history.php reset_satellite_history.php save_note.php toggle_decommission.php; do
    test -s "public/$endpoint"
    grep -q "require_admin" "public/$endpoint"
    grep -q "require_csrf" "public/$endpoint"
    grep -q "require_post" "public/$endpoint"
done

grep -q "PHP_SAPI !== 'cli'" bin/import_zabbix.php
grep -q "PHP_SAPI !== 'cli'" bin/import-satellite-history-dir.php
grep -q "PHP_SAPI !== 'cli'" bin/process-satellite-inbox.php
grep -q "flock(\$lock, LOCK_EX | LOCK_NB)" bin/process-satellite-inbox.php
grep -q "\.part" setup/satellite/export-and-send.sh
test -s setup/systemd/mooncrater-satellite-import.service
test -s setup/systemd/mooncrater-satellite-import.timer
grep -q "session_regenerate_id" public/login.php
grep -q "PDO::PARAM_BOOL" public/import_satellite.php
test "$(find public -maxdepth 1 -type f ! -name '*.php' ! -name '.htaccess' | wc -l)" -eq 0
echo "Static security checks passed."
