# Installation on Apache and PostgreSQL

For a dedicated Red Hat Enterprise Linux 10 server, use the complete installer documented in
[`setup/rhel10/README.md`](../setup/rhel10/README.md). It installs and enables the required services
in addition to deploying MoonCreater.

1. Install Apache, PHP with `pdo_pgsql`, PostgreSQL and `psql`.
2. Place the repository in `/opt/mooncreater` or run `sudo setup/install.sh`.
3. Create a PostgreSQL database and a dedicated owner account.
4. Provide `DB_USER`, `DB_PASS` and the optional values from `.env.example` to PHP/Apache.
5. Run `php bin/migrate.php` with the same environment.
6. Run `php bin/create-admin.php ADMIN_USERNAME` and enter a password of at least eight characters.
7. Install `setup/apache/mooncreater.conf.example`, changing `ServerName` if necessary.
8. Validate with `apachectl configtest`, reload Apache and run `setup/check.sh`.

Do not place credentials in the repository or directly in the public virtual-host file. The
example uses a root-owned `/etc/mooncreater/apache-env.conf`, which should be mode `0640` or more
restrictive. Enable HTTPS before exposing the service beyond a trusted network.

## Upgrade

On RHEL 10 installations created with the dedicated installer, update the Git clone and run
`sudo ./setup/rhel10/update.sh`. The updater creates a PostgreSQL backup and preserves credentials,
users and runtime data. See `setup/rhel10/README.md` for details.

Back up PostgreSQL, replace the application files while preserving local configuration, run
`php bin/migrate.php`, execute `setup/check.sh`, then reload Apache. Roll back application files
and restore the database backup together if a schema migration cannot be reversed safely.
