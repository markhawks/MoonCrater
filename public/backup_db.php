<?php
require __DIR__ . '/../app/bootstrap.php';
require_post();
require_admin($pdo);
require_csrf();

try {
    // FORCE CORRECT TIMEZONE (ITALY)
    date_default_timezone_set('Europe/Rome');

    // Ora genererà il timestamp corretto (es. 1228 invece di 1028)
    $timestamp = date('Ymd_Hi');

    // Backup Inventory External
    $pdo->exec("CREATE TABLE IF NOT EXISTS bak_external_{$timestamp} AS SELECT * FROM inventory_external");

    // Backup Inventory Satellite
    $pdo->exec("CREATE TABLE IF NOT EXISTS bak_satellite_{$timestamp} AS SELECT * FROM inventory_satellite");

    // Backup Note
    $pdo->exec("CREATE TABLE IF NOT EXISTS bak_notes_{$timestamp} AS SELECT * FROM server_notes");

    audit_event('database.backup', ['timestamp' => $timestamp]);
    header('Location: settings.php?backup=success#inventory-data', true, 303);
} catch (Exception $e) {
    error_log("Database backup failed: " . $e->getMessage()); http_response_code(500); exit("Backup failed.");
}
