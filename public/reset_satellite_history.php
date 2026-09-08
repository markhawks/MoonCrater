<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require_post();
require_admin($pdo);
require_csrf();

try {
    $deleted = $pdo->exec('DELETE FROM satellite_import_runs');
    audit_event('satellite.history_reset', ['deleted_runs' => $deleted]);
    header('Location: migration_trends.php?history_reset=' . (int) $deleted);
    exit;
} catch (Throwable $e) {
    error_log('Satellite history reset failed: ' . $e->getMessage());
    header('Location: migration_trends.php?error=history_reset');
    exit;
}
