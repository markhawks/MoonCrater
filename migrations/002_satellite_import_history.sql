CREATE TABLE IF NOT EXISTS satellite_import_runs (
    id BIGSERIAL PRIMARY KEY,
    imported_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    source_filename TEXT NOT NULL,
    imported_count INTEGER NOT NULL DEFAULT 0,
    skipped_count INTEGER NOT NULL DEFAULT 0,
    missing_count INTEGER NOT NULL DEFAULT 0,
    has_status_column BOOLEAN NOT NULL DEFAULT FALSE
);

CREATE TABLE IF NOT EXISTS satellite_os_snapshots (
    run_id BIGINT NOT NULL REFERENCES satellite_import_runs(id) ON DELETE CASCADE,
    os_major VARCHAR(2) NOT NULL,
    os_version VARCHAR(32) NOT NULL,
    host_count INTEGER NOT NULL CHECK (host_count >= 0),
    PRIMARY KEY (run_id, os_version)
);

CREATE INDEX IF NOT EXISTS idx_satellite_import_runs_imported_at
    ON satellite_import_runs (imported_at DESC);
