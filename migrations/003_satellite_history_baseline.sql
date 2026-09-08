DO $$
DECLARE
    baseline_id BIGINT;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM satellite_import_runs) THEN
        INSERT INTO satellite_import_runs
            (source_filename, imported_count, skipped_count, missing_count, has_status_column)
        SELECT
            'baseline-current-state.csv',
            COUNT(*),
            0,
            COUNT(*) FILTER (WHERE status = 'missing'),
            FALSE
        FROM inventory_satellite
        WHERE COALESCE(role, '') NOT IN ('satellite', 'capsule')
        RETURNING id INTO baseline_id;

        INSERT INTO satellite_os_snapshots (run_id, os_major, os_version, host_count)
        SELECT baseline_id, split_part(os_version, '.', 1), os_version, COUNT(*)
        FROM (
            SELECT substring(os FROM '([0-9]+(\.[0-9]+)?)') AS os_version
            FROM inventory_satellite
            WHERE status = 'active'
              AND COALESCE(role, '') NOT IN ('satellite', 'capsule')
        ) versions
        WHERE split_part(os_version, '.', 1) IN ('7', '8', '9', '10')
        GROUP BY os_version;
    END IF;
END $$;
