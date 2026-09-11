# Changelog

All notable changes to MoonCrater are documented here. Dates use the ISO `YYYY-MM-DD` format.

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
