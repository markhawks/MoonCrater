# Changelog

All notable changes to MoonCrater are documented here. Dates use the ISO `YYYY-MM-DD` format.

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
