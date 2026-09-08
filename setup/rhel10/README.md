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
