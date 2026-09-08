#!/usr/bin/env bash
set -euo pipefail

# MoonCrater unattended/semi-interactive installer for a dedicated RHEL 10 host.
# Run from a cloned repository as root. Override defaults with environment variables.

source_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
install_dir="${MOONCRATER_INSTALL_DIR:-/opt/mooncrater}"
db_name="${MOONCRATER_DB_NAME:-mooncrater}"
db_user="${MOONCRATER_DB_USER:-mooncrater}"
server_name="${MOONCRATER_SERVER_NAME:-$(hostname -f)}"
admin_user="${MOONCRATER_ADMIN_USER:-admin}"
configure_firewall="${MOONCRATER_CONFIGURE_FIREWALL:-yes}"

die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
info() { printf '\n==> %s\n' "$*"; }

[[ $EUID -eq 0 ]] || die 'Run this installer as root.'
[[ -r /etc/os-release ]] || die 'Cannot identify the operating system.'
. /etc/os-release
[[ ${ID:-} == rhel && ${VERSION_ID%%.*} == 10 ]] || \
    die "This installer supports RHEL 10 only (detected ${PRETTY_NAME:-unknown})."
[[ $db_name =~ ^[a-z_][a-z0-9_]*$ ]] || die 'Invalid database name.'
[[ $db_user =~ ^[a-z_][a-z0-9_]*$ ]] || die 'Invalid database user.'
[[ $admin_user =~ ^[A-Za-z0-9_.@-]+$ ]] || die 'Invalid administrator username.'
[[ $server_name =~ ^[A-Za-z0-9][A-Za-z0-9.-]*$ ]] || \
    die 'Invalid server name.'
[[ $install_dir =~ ^/[A-Za-z0-9._/-]+$ && $install_dir != / ]] || die 'Invalid installation path.'
[[ $configure_firewall == yes || $configure_firewall == no ]] || \
    die 'MOONCRATER_CONFIGURE_FIREWALL must be yes or no.'

if [[ -z ${MOONCRATER_DB_PASSWORD:-} ]]; then
    read -r -s -p 'PostgreSQL application password: ' db_password
    printf '\n'
    read -r -s -p 'Confirm PostgreSQL password: ' db_password_confirm
    printf '\n'
    [[ $db_password == "$db_password_confirm" ]] || die 'Database passwords do not match.'
else
    db_password=$MOONCRATER_DB_PASSWORD
fi
[[ ${#db_password} -ge 12 ]] || die 'The database password must contain at least 12 characters.'
[[ $db_password =~ ^[A-Za-z0-9._~!@%+=:,/-]+$ ]] || \
    die 'The database password contains characters unsupported by the Apache environment file.'

if [[ -z ${MOONCRATER_ADMIN_PASSWORD:-} ]]; then
    read -r -s -p "Initial password for ${admin_user}: " admin_password
    printf '\n'
    read -r -s -p 'Confirm administrator password: ' admin_password_confirm
    printf '\n'
    [[ $admin_password == "$admin_password_confirm" ]] || die 'Administrator passwords do not match.'
else
    admin_password=$MOONCRATER_ADMIN_PASSWORD
fi
[[ ${#admin_password} -ge 8 ]] || die 'The administrator password must contain at least 8 characters.'

info 'Installing RHEL packages'
dnf install -y \
    httpd php php-cli php-pgsql php-mbstring \
    postgresql postgresql-server policycoreutils-python-utils \
    firewalld git

info 'Installing application files'
if [[ $source_dir != "$install_dir" ]]; then
    MOONCRATER_INSTALL_DIR="$install_dir" "$source_dir/setup/install.sh"
else
    install -d -m 0750 /etc/mooncrater "$install_dir/var/backups" "$install_dir/var/uploads"
fi

info 'Initializing and starting PostgreSQL'
if [[ ! -s /var/lib/pgsql/data/PG_VERSION ]]; then
    postgresql-setup --initdb --unit postgresql
fi
systemctl enable --now postgresql

hba_file="$(runuser -u postgres -- psql -Atqc 'SHOW hba_file')"
[[ -n $hba_file && -f $hba_file ]] || die 'Could not locate pg_hba.conf.'
if ! grep -Eq '^host[[:space:]]+all[[:space:]]+all[[:space:]]+127\.0\.0\.1/32[[:space:]]+scram-sha-256' "$hba_file"; then
    [[ -e ${hba_file}.mooncrater.bak ]] || cp -a "$hba_file" "${hba_file}.mooncrater.bak"
    sed -i '1ihost all all ::1/128 scram-sha-256' "$hba_file"
    sed -i '1ihost all all 127.0.0.1/32 scram-sha-256' "$hba_file"
    systemctl reload postgresql
fi

info 'Creating the PostgreSQL role and database'
runuser -u postgres -- psql --set=ON_ERROR_STOP=1 \
    --set=app_user="$db_user" --set=app_db="$db_name" --set=app_password="$db_password" <<'SQL'
SET password_encryption = 'scram-sha-256';
SELECT format('CREATE ROLE %I LOGIN PASSWORD %L', :'app_user', :'app_password')
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = :'app_user') \gexec
SELECT format('ALTER ROLE %I WITH LOGIN PASSWORD %L', :'app_user', :'app_password') \gexec
SELECT format('CREATE DATABASE %I OWNER %I', :'app_db', :'app_user')
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = :'app_db') \gexec
SELECT format('ALTER DATABASE %I OWNER TO %I', :'app_db', :'app_user') \gexec
SQL

info 'Writing the protected Apache environment file'
install -d -m 0750 /etc/mooncrater
umask 0077
{
    printf 'SetEnv DB_HOST localhost\n'
    printf 'SetEnv DB_PORT 5432\n'
    printf 'SetEnv DB_NAME %s\n' "$db_name"
    printf 'SetEnv DB_USER %s\n' "$db_user"
    printf 'SetEnv DB_PASS "%s"\n' "$db_password"
    printf 'SetEnv APP_TIMEZONE Europe/Rome\n'
    printf 'SetEnv APP_NAME MoonCrater\n'
    printf 'SetEnv APP_SUBTITLE "Infrastructure Control Plane Patching"\n'
    printf 'SetEnv CUSTOMER_NAME "Acme Corporation"\n'
    printf 'SetEnv CUSTOMER_LOGO assets/img/customer-default.svg\n'
} > /etc/mooncrater/apache-env.conf
chmod 0640 /etc/mooncrater/apache-env.conf
chown root:apache /etc/mooncrater/apache-env.conf

info 'Installing the Apache virtual host'
sed \
    -e "s|mooncrater\.example\.test|${server_name}|" \
    -e "s|/opt/mooncrater|${install_dir}|g" \
    "$install_dir/setup/apache/mooncrater.conf.example" \
    > /etc/httpd/conf.d/mooncrater.conf
chmod 0644 /etc/httpd/conf.d/mooncrater.conf

info 'Applying SELinux labels and permissions'
semanage fcontext -a -t httpd_sys_content_t "${install_dir}/public(/.*)?" 2>/dev/null || \
    semanage fcontext -m -t httpd_sys_content_t "${install_dir}/public(/.*)?"
restorecon -RF "$install_dir/public"
setsebool -P httpd_can_network_connect_db on

info 'Applying database migrations and creating the administrator'
export DB_HOST=localhost DB_PORT=5432 DB_NAME="$db_name" DB_USER="$db_user" DB_PASS="$db_password"
export APP_TIMEZONE=Europe/Rome MOONCRATER_ADMIN_PASSWORD="$admin_password"
php "$install_dir/bin/migrate.php"
php "$install_dir/bin/create-admin.php" "$admin_user"
unset MOONCRATER_ADMIN_PASSWORD admin_password db_password

info 'Validating and starting Apache'
apachectl configtest
systemctl enable --now httpd

if [[ $configure_firewall == yes ]]; then
    info 'Enabling firewalld and allowing HTTP'
    systemctl enable --now firewalld
    firewall-cmd --permanent --add-service=http
    firewall-cmd --reload
fi

info 'Running MoonCrater checks'
"$install_dir/setup/check.sh"

printf '\nMoonCrater installation completed.\n'
printf 'URL: http://%s/\n' "$server_name"
printf 'Administrator: %s\n' "$admin_user"
printf 'Next step: configure HTTPS before exposing the portal outside a trusted network.\n'
