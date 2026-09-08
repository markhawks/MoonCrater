# Pre-release refactoring checklist

Completed on 2026-09-08 for version 1.31.

1. **Public web root** — Apache exposes only `public/`.
2. **Application layer** — bootstrap, configuration and security moved to `app/`.
3. **Domain layer** — inventory rules isolated in `app/Domain/`.
4. **View foundation** — reusable escaping and asset helpers added under `app/View/`.
5. **Static assets** — CSS and images moved to `public/assets/`.
6. **CLI isolation** — migration, first-admin and Zabbix commands live in `bin/`.
7. **Fresh database support** — `000_initial_schema.sql` creates the base schema.
8. **Tracked upgrades** — `bin/migrate.php` records migration filenames and checksums.
9. **No runtime DDL** — web bootstrap no longer creates database tables.
10. **Deployment tooling** — Apache examples and a non-container installer added in `setup/`.
11. **Automated checks** — preflight validates PHP, security rules, domain tests and public surface.
12. **Open-source hygiene** — customer CSVs remain ignored; architecture, install, security and
    contribution documents were added; the project is licensed AGPL-3.0-or-later.
