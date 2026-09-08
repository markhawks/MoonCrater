BEGIN;

CREATE TABLE IF NOT EXISTS ivanti_import_runs (
    id BIGSERIAL PRIMARY KEY,
    imported_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    source_filename TEXT NOT NULL,
    imported_count INTEGER NOT NULL DEFAULT 0 CHECK (imported_count >= 0),
    inserted_count INTEGER NOT NULL DEFAULT 0 CHECK (inserted_count >= 0),
    updated_count INTEGER NOT NULL DEFAULT 0 CHECK (updated_count >= 0),
    skipped_count INTEGER NOT NULL DEFAULT 0 CHECK (skipped_count >= 0)
);

CREATE INDEX IF NOT EXISTS idx_ivanti_import_runs_imported_at
    ON ivanti_import_runs (imported_at DESC);

COMMIT;
