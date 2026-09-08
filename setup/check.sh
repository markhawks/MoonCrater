#!/usr/bin/env bash
set -euo pipefail
project_dir="$(cd "$(dirname "$0")/.." && pwd)"
cd "$project_dir"

for command in php psql rg; do command -v "$command" >/dev/null || { echo "Missing command: $command" >&2; exit 1; }; done
php -m | rg -q '^pdo_pgsql$' || { echo 'Missing PHP extension: pdo_pgsql' >&2; exit 1; }
for file in public/*.php app/*.php app/Domain/*.php app/View/*.php bin/*.php; do php -l "$file" >/dev/null; done
bash tests/static_checks.sh
php tests/domain_test.php
test -f public/.htaccess
test -f public/assets/img/favicon-mooncreater-130.png
echo 'MoonCreater preflight checks passed.'
