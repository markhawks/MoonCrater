# Changelog

All notable changes to MoonCrater are documented here. Dates use the ISO `YYYY-MM-DD` format.

## [1.53] - 2026-11-26

- Added opt-in Satellite registration from the portal with live Hammer verification and per-host PostgreSQL inventory updates.
- Added per-job target SSH credentials over HTTPS, encrypted queue storage, and the pinned Foreman collection manifest.
- Added FQDN resolution and the RPM updater with database backup and package digest verification.

## [1.52] - 2026-11-26

- Added an administrator-only Satellite Onboarding preview with inventory validation, selection review, and simulation history.
- Added a read-only Hammer precheck over SSH with English JSON output enforced by the Satellite wrapper.
- Added the localhost Ansible test worker and its opt-in systemd timer.
- Added migrations for simulations and local test outcomes and documented the RPM upgrade procedure.

## [1.51] - 2026-11-25

- Added a dashboard shortcut for importing Satellite CSV files and improved the contrast of search filters.
- Added Content View filters, migration counters, and configurable visibility for comparison and statistics links.
- Added clickable Satellite anomaly counters for check-ins, missing IP or Content View, invalid kernels, and non-FQDN hostnames.
- Included unhealthy hosts in Satellite OS distribution and RHEL and kernel statistics, and aligned the RHEL health cards and sortable tables.
- Reorganized the dashboard counters and Statistics & Views links.
- Published a new RPM for fresh installations on RHEL 10.3.

## [1.50] - 2026-11-25

- Documented RHEL 10.2 and 10.3 as installer targets, with RHEL 10.3 as the development and production baseline.

- Documented Satellite Onboarding and the incremental migration from PHP to Python.
- Moved manual CSV imports, snapshot creation, and recent backups to Settings → Inventory Data.
- Added separate External and Satellite resets and a Factory Reset for both inventories and server notes.
- Generalized Ivanti to External Inventory, with a PostgreSQL migration that preserves existing inventory and import history.
- Made Satellite Inventory View the primary dashboard table and added independent column controls for both inventory views.
- Added dashboard settings to show or hide Unified Inventory View, External Active, External OS Distribution, Missing in Satellite, and Coverage.
- Split the dashboard CSV exports into dedicated Unified and Satellite downloads and consolidated administration under Settings.
- Improved customer logo presentation and aligned the login logo with the dashboard.

## [1.41] - 2026-09-16

### Fixed

- Standardized all user-facing portal text, messages, confirmations, and embedded changelog entries
  in clear English; replaced Italian `N/D` display fallbacks with `N/A` while retaining legacy-data
  compatibility.
- Aligned the Diff Inventory and Migration Trends headers and Back to Dashboard buttons with the
  statistics pages.
- Expanded Migration Trends to the full browser width and made the chart fill all available
  horizontal space while preserving horizontal scrolling for long histories.
- Corrected Satellite and Ivanti CSV directory ownership, group access, and SELinux labels so PHP
  can read local snapshots after fresh installations and updates.

## [1.40] - 2026-09-16

### Added

- RHEL anomaly report at the bottom of Diff Inventory View, covering Ivanti-only hosts,
  Satellite-only hosts, Satellite check-ins older than 30 days, and hosts retired in Ivanti but
  still present in Satellite.
- Unique affected-host total, per-anomaly counters, sequential row numbering, and anomaly-type
  sorting for the verification report.
- Email-friendly table copy, CSV export, and styled Excel-compatible export with summary and detail
  worksheets, filters, frozen headers, and anomaly-specific colors.
- Editable Customer Identity name in Settings, stored in application settings and applied to the
  login page and portal header, with validation, auditing, and default-name restoration.

### Changed

- Migration Trends chart now expands dynamically to preserve readable spacing across the complete
  snapshot history and provides horizontal scrolling instead of compressing older points.

## [1.39] - 2026-09-11

### Added

- New read-only Diff Inventory View comparing the latest local Ivanti and Satellite CSV snapshots.
- Linux-family sections for Red Hat Enterprise Linux, Oracle Linux, SUSE Linux, Ubuntu, CentOS,
  Retired, and Unknown, with per-release counters.
- Separate Ivanti/Satellite totals and per-section counts of check-in anomalies older than 30 days.
- Sortable Ivanti and Satellite hostname and OS columns in every comparison table.
- Fluorescent-yellow highlighting for stale Satellite check-ins relative to the snapshot date.
- Automatic extraction-date fallback for Ivanti CSV files without a Scan Date column.

### Changed

- Unified Inventory View and Satellite Only are collapsed by default and toggle from their complete
  title bars using compact chevrons.
- Ivanti Hostname is visible by default; Satellite Only explicitly displays `N/A` in that column.
- Full CSV export mappings now match the updated Satellite Only and Unified Inventory structures.
- RHEL 10 installation and updates create or preserve the local `ivanti-import-csv` directory.

## [1.38] - 2026-09-08

### Added

- Automatic creation of the `mooncrater-import` operating-system account during RHEL 10
  installation and updates.
- Automatic creation of its home, protected `.ssh` directory, and writable Satellite inbox with
  the correct ownership and permissions.
- Clear installer completion messages identifying the Satellite exporter and public-key
  authorization steps.

### Changed

- Reordered the automatic-feed documentation into MoonCrater installation, Satellite exporter
  installation, SSH public-key authorization, and manual end-to-end verification.
- Documented that the importer is a systemd `oneshot` service and that its persistent schedule is
  represented by `mooncrater-satellite-import.timer`.

## [1.37] - 2026-09-08

### Added

- Automatic five-minute processing of the `satellite-import-csv` inbox.
- Chronological batch processing: the newest Satellite CSV updates the operational inventory,
  comparisons, and statistics; older CSV files populate Migration Trends.
- Timestamp extraction from filenames in `DDMMYYYY-HHMM`, `YYYY-MM-DD-HHMM`, and compatible
  date-only formats.
- Persistent current-snapshot marker, previously imported-file detection, retry behavior, and an
  execution lock.
- Atomic SCP delivery through a hidden temporary file followed by a remote rename.
- MoonCrater systemd service and timer plus a dedicated SSH ingestion-account configurator.
- Generic Satellite-side installer that prompts for the MoonCrater target, generates a dedicated
  SSH key, installs the exporter under `/root/mooncrater-script`, and schedules it daily at 02:00.
- Historical directory import, browser folder import, filename-based dates, and graph-only reset.

### Changed

- Migration Trends now displays snapshot time as well as date and preserves multiple uniquely
  named imports from the same day.
- The RHEL 10 updater preserves the Satellite CSV inbox and refreshes its automatic-import units.

## [1.36] - 2026-09-08

- Added automatic migration from legacy `/opt/mooncreater` and `/etc/mooncreater` paths.
- Updated Apache, SELinux, and rollback handling for the corrected MoonCrater layout.

## [1.35] - 2026-09-08

- Corrected the product name from MoonCreater to MoonCrater across application assets and setup.

## [1.34] - 2026-09-08

- Extended the valid Satellite last check-in threshold from 3 to 30 days.

## [1.33] - 2026-09-08

- Added transactional Ivanti CSV imports from the dashboard and CLI.

## [1.32] - 2026-09-08

- Added the dedicated RHEL 10 installer and safe updater with PostgreSQL backup and file rollback.

Earlier release notes remain available in the in-application changelog.
