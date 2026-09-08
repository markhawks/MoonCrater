# RHEL 10 installer

This directory contains the dedicated installer for a new Red Hat Enterprise Linux 10 server.
It is intentionally separate from the generic file-copy installer in `setup/install.sh`.

Run it from a cloned MoonCreater repository:

```bash
sudo ./setup/rhel10/install.sh
```

The installer prompts for the PostgreSQL and initial administrator passwords. It installs the
required DNF packages, initializes PostgreSQL, creates the application role and database, installs
the Apache virtual host, configures SELinux, optionally opens HTTP in firewalld, applies database
migrations, creates the administrator and enables PostgreSQL and Apache at boot.

Defaults can be changed with environment variables:

```bash
sudo MOONCREATER_SERVER_NAME=mooncreater.example.com \
  MOONCREATER_ADMIN_USER=admin \
  MOONCREATER_INSTALL_DIR=/opt/mooncreater \
  ./setup/rhel10/install.sh
```

Supported variables:

| Variable | Default |
|---|---|
| `MOONCREATER_INSTALL_DIR` | `/opt/mooncreater` |
| `MOONCREATER_DB_NAME` | `mooncreater` |
| `MOONCREATER_DB_USER` | `mooncreater` |
| `MOONCREATER_SERVER_NAME` | System FQDN (`hostname -f`) |
| `MOONCREATER_ADMIN_USER` | `admin` |
| `MOONCREATER_CONFIGURE_FIREWALL` | `yes` |

For automation, passwords may be supplied as `MOONCREATER_DB_PASSWORD` and
`MOONCREATER_ADMIN_PASSWORD`. Avoid shell history and use a protected environment file or a secret
manager. HTTPS is deliberately not configured because its setup depends on the site's DNS name and
certificate source.

## Updating an existing installation

Pull the desired release into the original Git clone, then run the dedicated updater:

```bash
git pull --ff-only origin main
sudo ./setup/rhel10/update.sh
```

The updater reads the existing database credentials from `/etc/mooncreater/apache-env.conf`; it
does not change them and does not recreate the administrator. Before applying migrations it creates
a custom-format `pg_dump` backup under `/opt/mooncreater/var/backups`. It stages and checks the new
release before replacing the application directory, reloads Apache and performs an HTTP check.

Application files are automatically restored if activation or the HTTP check fails. Database
migrations are not automatically reversed: use the reported PostgreSQL backup for a coordinated
database restore if a migration itself must be rolled back.

Optional update variables:

| Variable | Default |
|---|---|
| `MOONCREATER_INSTALL_DIR` | `/opt/mooncreater` |
| `MOONCREATER_ENV_FILE` | `/etc/mooncreater/apache-env.conf` |
| `MOONCREATER_BACKUP_DIR` | `/opt/mooncreater/var/backups` |
| `MOONCREATER_HEALTH_URL` | `http://127.0.0.1/` |
| `MOONCREATER_ALLOW_DIRTY_SOURCE` | `no` |
