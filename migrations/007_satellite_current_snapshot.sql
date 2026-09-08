ALTER TABLE satellite_import_runs
    ADD COLUMN IF NOT EXISTS is_current BOOLEAN NOT NULL DEFAULT FALSE;

CREATE UNIQUE INDEX IF NOT EXISTS idx_satellite_import_runs_current
    ON satellite_import_runs (is_current)
    WHERE is_current = TRUE;
