BEGIN;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS role VARCHAR(20) NOT NULL DEFAULT 'user';

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'users_role_valid') THEN
        ALTER TABLE users ADD CONSTRAINT users_role_valid CHECK (role IN ('admin', 'user'));
    END IF;
END $$;

CREATE UNIQUE INDEX IF NOT EXISTS users_username_unique_idx ON users (LOWER(username));
CREATE INDEX IF NOT EXISTS inventory_ivanti_hostname_normalized_idx
    ON inventory_ivanti (LOWER(TRIM(hostname)));
CREATE INDEX IF NOT EXISTS inventory_ivanti_status_idx ON inventory_ivanti (status);
CREATE INDEX IF NOT EXISTS inventory_satellite_hostname_normalized_idx
    ON inventory_satellite (LOWER(TRIM(hostname)));
CREATE INDEX IF NOT EXISTS inventory_satellite_status_role_idx
    ON inventory_satellite (status, role);
CREATE INDEX IF NOT EXISTS inventory_satellite_location_idx ON inventory_satellite (location);
CREATE UNIQUE INDEX IF NOT EXISTS server_notes_hostname_normalized_idx
    ON server_notes (LOWER(TRIM(hostname)));
CREATE UNIQUE INDEX IF NOT EXISTS zabbix_hosts_hostname_normalized_idx
    ON zabbix_hosts (LOWER(TRIM(hostname)));

COMMIT;
