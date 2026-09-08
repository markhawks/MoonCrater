BEGIN;

CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    role VARCHAR(20) NOT NULL DEFAULT 'user',
    last_password_reset_at TIMESTAMP NULL
);

CREATE TABLE IF NOT EXISTS inventory_ivanti (
    id SERIAL PRIMARY KEY,
    hostname TEXT,
    os TEXT,
    ip TEXT,
    scan_date TEXT,
    status VARCHAR(20) DEFAULT 'active',
    role VARCHAR(20) NOT NULL DEFAULT 'host'
);

CREATE TABLE IF NOT EXISTS inventory_satellite (
    id SERIAL PRIMARY KEY,
    hostname VARCHAR(255) UNIQUE,
    os VARCHAR(100),
    ip VARCHAR(50),
    kernel VARCHAR(100),
    content_view_environment VARCHAR(150),
    location VARCHAR(100),
    last_checkin VARCHAR(100),
    status VARCHAR(20) DEFAULT 'active',
    role VARCHAR(20) NOT NULL DEFAULT 'host'
);

CREATE TABLE IF NOT EXISTS server_notes (
    hostname TEXT PRIMARY KEY,
    migration_date DATE,
    notes TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS zabbix_hosts (
    hostname VARCHAR(255) PRIMARY KEY,
    role VARCHAR(100) NOT NULL,
    last_update VARCHAR(50),
    zabbix_version VARCHAR(20) DEFAULT NULL
);

COMMIT;
