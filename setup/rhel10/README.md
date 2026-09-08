# RHEL 10 installer

This directory contains the dedicated installer for a new Red Hat Enterprise Linux 10 server.
It is intentionally separate from the generic file-copy installer in `setup/install.sh`.

Run it from a cloned MoonCrater repository:

```bash
sudo ./setup/rhel10/install.sh
```

The installer prompts for the PostgreSQL and initial administrator passwords. It installs the
required DNF packages, initializes PostgreSQL, creates the application role and database, installs
the Apache virtual host, configures SELinux, optionally opens HTTP in firewalld, applies database
migrations, creates the administrator, enables PostgreSQL and Apache at boot, creates the
`mooncrater-import` operating-system account and home directory, prepares its Satellite inbox, and
enables the five-minute Satellite inbox timer.

Defaults can be changed with environment variables:

```bash
sudo MOONCRATER_SERVER_NAME=mooncrater.example.com \
  MOONCRATER_ADMIN_USER=admin \
  MOONCRATER_INSTALL_DIR=/opt/mooncrater \
  ./setup/rhel10/install.sh
```

Supported variables:

| Variable | Default |
|---|---|
| `MOONCRATER_INSTALL_DIR` | `/opt/mooncrater` |
| `MOONCRATER_DB_NAME` | `mooncrater` |
| `MOONCRATER_DB_USER` | `mooncrater` |
| `MOONCRATER_SERVER_NAME` | System FQDN (`hostname -f`) |
| `MOONCRATER_ADMIN_USER` | `admin` |
| `MOONCRATER_CONFIGURE_FIREWALL` | `yes` |

For automation, passwords may be supplied as `MOONCRATER_DB_PASSWORD` and
`MOONCRATER_ADMIN_PASSWORD`. Avoid shell history and use a protected environment file or a secret
manager. HTTPS is deliberately not configured because its setup depends on the site's DNS name and
certificate source.

## Updating an existing installation

Pull the desired release into the original Git clone, then run the dedicated updater:

```bash
git pull --ff-only origin main
sudo ./setup/rhel10/update.sh
```

The updater reads the existing database credentials from `/etc/mooncrater/apache-env.conf`; it
does not change them and does not recreate the administrator. Before applying migrations it creates
a custom-format `pg_dump` backup under `/opt/mooncrater/var/backups`. It stages and checks the new
release before replacing the application directory, reloads Apache and performs an HTTP check.

Installations created as MoonCreater through version 1.34 are detected automatically. Before the
application update, the updater invokes `migrate-layout.sh` to rename `/opt/mooncreater` to
`/opt/mooncrater` and `/etc/mooncreater` to `/etc/mooncrater`, then updates Apache and SELinux. The
database itself is not renamed or modified by this filesystem migration. The former
`MOONCREATER_*` update variables remain accepted as compatibility aliases.

Application files are automatically restored if activation or the HTTP check fails. Database
migrations are not automatically reversed: use the reported PostgreSQL backup for a coordinated
database restore if a migration itself must be rolled back.

The updater also creates the import account when missing, preserves `satellite-import-csv/`, and
refreshes the automatic import service. Install the Satellite exporter and then authorize its
generated public key as documented in
[`setup/satellite/README.md`](../satellite/README.md).

The Satellite importer is a systemd `oneshot` service rather than a continuously running daemon.
It is therefore normal for `mooncrater-satellite-import.service` to show `inactive (dead)` after a
successful execution. Use `systemctl status mooncrater-satellite-import.timer` or
`systemctl list-timers --all` to verify the persistent five-minute schedule. The Satellite feed
documentation contains the complete diagnostic and manual-start commands.

Optional update variables:

| Variable | Default |
|---|---|
| `MOONCRATER_INSTALL_DIR` | `/opt/mooncrater` |
| `MOONCRATER_ENV_FILE` | `/etc/mooncrater/apache-env.conf` |
| `MOONCRATER_BACKUP_DIR` | `/opt/mooncrater/var/backups` |
| `MOONCRATER_HEALTH_URL` | `http://127.0.0.1/` |
| `MOONCRATER_ALLOW_DIRTY_SOURCE` | `no` |
