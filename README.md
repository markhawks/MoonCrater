<p align="center">
  <img src="public/assets/img/mooncreater-icon-v2.png" alt="MoonCreater logo" width="220">
</p>

<h1 align="center">MoonCreater</h1>

<p align="center">
  <strong>Infrastructure Control Plane Patching</strong><br>
  A PHP and PostgreSQL portal for correlating Linux inventories from Ivanti, Red Hat Satellite,
  and Zabbix.
</p>

<p align="center">
  <a href="https://www.gnu.org/licenses/agpl-3.0.html"><img src="https://img.shields.io/badge/License-AGPL_v3_or_later-blue.svg" alt="License: AGPL v3 or later"></a>
  <img src="https://img.shields.io/badge/PHP-8%2B-777BB4.svg" alt="PHP 8+">
  <img src="https://img.shields.io/badge/PostgreSQL-16%2B-4169E1.svg" alt="PostgreSQL 16+">
</p>

<p align="center">
  English · <a href="docs/README.it.md">Italiano</a>
</p>

MoonCreater provides a unified operational view of Linux patching inventories. It highlights
missing and unhealthy hosts, tracks RHEL migration progress over time, keeps Satellite and Capsule
infrastructure visible, and supports migration planning notes without replacing the source systems.

Current application version: **1.31**.

## Features

- Unified Ivanti and Red Hat Satellite inventory.
- Host states: `active`, `unhealthy`, `missing`, and `excluded`.
- Dedicated management for Satellite and Capsule infrastructure hosts.
- RHEL 7, 8, 9, and 10 inventory and kernel statistics.
- Historical Satellite imports with migration charts and per-release differences.
- Zabbix inventory and migration progress.
- Patching notes and planned migration dates.
- Administrator and read-only user roles.
- Configurable customer name and logo.
- CSRF protection, hardened sessions, authorization checks, and audit events.

## Project layout

Apache must expose only the `public/` directory.

```text
app/          Application bootstrap, configuration, security, and domain code
bin/          CLI migration, administration, and import commands
docs/         Architecture, installation, and translated documentation
migrations/   Ordered PostgreSQL migrations
public/       PHP web endpoints and static assets — the Apache DocumentRoot
setup/        Installer, preflight checks, and Apache examples
tests/        Static security and domain tests
var/          Local runtime data, ignored by Git
```

See [Architecture](docs/ARCHITECTURE.md) for more detail.

## Requirements

- Apache HTTP Server
- PHP 8 or later with PDO PostgreSQL support
- PostgreSQL
- `psql` for installation checks
- HTTPS for production deployments

No container runtime is required.

## Installation

Clone the repository and run the installer as root:

```bash
git clone https://github.com/markhawks/MoonCreater.git
cd MoonCreater
sudo ./setup/install.sh
```

The default installation path is `/opt/mooncreater`. It can be changed with
`MOONCREATER_INSTALL_DIR`.

Create a PostgreSQL database owned by a dedicated application user. Configure the variables shown
in `.env.example`, then run:

```bash
php bin/migrate.php
php bin/create-admin.php admin
./setup/check.sh
```

Install and adapt `setup/apache/mooncreater.conf.example`, validate the Apache configuration, and
reload the service:

```bash
apachectl configtest
sudo systemctl reload httpd
```

For the complete procedure, see [Installation](docs/INSTALL.md).

On a RHEL 10 host installed with the dedicated installer, update from the original Git clone. The
updater backs up PostgreSQL and preserves users, application data and credentials:

```bash
git pull --ff-only origin main
sudo ./setup/rhel10/update.sh
```

## Configuration

Required environment variables:

| Variable | Description |
|---|---|
| `DB_USER` | Dedicated PostgreSQL user |
| `DB_PASS` | PostgreSQL password |

Optional variables:

| Variable | Default |
|---|---|
| `DB_HOST` | `localhost` |
| `DB_PORT` | `5432` |
| `DB_NAME` | `patching` |
| `APP_TIMEZONE` | `Europe/Rome` |
| `APP_NAME` | `MoonCreater` |
| `APP_SUBTITLE` | `Infrastructure Control Plane Patching` |
| `CUSTOMER_NAME` | `Acme Corporation` |
| `CUSTOMER_LOGO` | `assets/img/customer-default.svg` |

Never commit real credentials, customer inventories, database dumps, or production configuration.

## Database migrations

`bin/migrate.php` applies migrations in filename order and records their SHA-256 checksums in
`schema_migrations`. Migration `000_initial_schema.sql` supports installation on an empty database.

Run migrations with a database account that owns the MoonCreater schema:

```bash
php bin/migrate.php
```

Do not edit a migration after it has been published. Add a new numbered migration instead.

## Satellite inventory rules

The current Satellite CSV represents the current source state:

- an `excluded` host remains excluded;
- a present host with a check-in no older than three days becomes `active`;
- a present host with an old, missing, or invalid check-in becomes `unhealthy`;
- a previously known host absent from the new CSV becomes `missing`;
- hosts assigned the `satellite` or `capsule` role are preserved by normal reconciliation.

Administrators can import the current inventory from the dashboard. CLI usage is also available:

```bash
php public/import_satellite.php /path/to/satellite-inventory.csv
```

Historical CSV files must be imported from the Migration Trends page. Historical imports create
snapshots only and never alter the current operational inventory.

## Zabbix import

The Zabbix import is intentionally CLI-only:

```bash
php bin/import_zabbix.php /path/to/zabbix-hosts.csv
```

## Backups

The dashboard backup action creates timestamped snapshot tables inside the same PostgreSQL
database. These snapshots are convenient for short-term operational recovery, but they are not a
complete backup. Use `pg_dump` and store copies on a separate system for disaster recovery.

## Development and verification

Run the complete preflight suite from the repository root:

```bash
./setup/check.sh
```

It validates PHP syntax, required extensions, security invariants, domain helpers, and the expected
public web surface.

See [Contributing](CONTRIBUTING.md) before submitting a change. Security issues should follow the
[Security Policy](SECURITY.md).

## License

Copyright © 2026 MoonCreater contributors.

MoonCreater is licensed under the **GNU Affero General Public License, version 3 or later**
(`AGPL-3.0-or-later`). Modified versions distributed or made available to users over a network must
comply with the license's copyleft and corresponding-source requirements. See [LICENSE](LICENSE) and
[NOTICE](NOTICE) for the complete terms and notice.
