#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

for file in public/*.php app/*.php app/Domain/*.php app/View/*.php bin/*.php; do php -l "$file" >/dev/null; done

if rg -n "define\(['\"]DB_(PASS|PASSWORD)['\"]" --glob '*.php' app public bin; then
    echo "Hard-coded credential found" >&2
    exit 1
fi

for removed in add_admin.php add_user.php add_users.php; do
    test ! -e "public/$removed"
done

for endpoint in action.php action_infra.php action_zabbix.php backup_db.php save_note.php toggle_decommission.php; do
    test -s "public/$endpoint"
    rg -q "require_admin" "public/$endpoint"
    rg -q "require_csrf" "public/$endpoint"
    rg -q "require_post" "public/$endpoint"
done

rg -q "PHP_SAPI !== 'cli'" bin/import_zabbix.php
rg -q "session_regenerate_id" public/login.php
test "$(find public -maxdepth 1 -type f ! -name '*.php' ! -name '.htaccess' | wc -l)" -eq 0
echo "Static security checks passed."
