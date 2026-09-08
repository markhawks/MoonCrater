<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Domain/inventory.php';
require_once __DIR__ . '/../app/Domain/satellite_history.php';
require_post();
require_admin($pdo);
require_csrf();

$explicitDate = trim((string) ($_POST['import_date'] ?? ''));
$uploads = $_FILES['satellite_csvs'] ?? $_FILES['satellite_csv'] ?? [];
$names = is_array($uploads['name'] ?? null) ? $uploads['name'] : [$uploads['name'] ?? ''];
$tmpNames = is_array($uploads['tmp_name'] ?? null) ? $uploads['tmp_name'] : [$uploads['tmp_name'] ?? ''];
$errors = is_array($uploads['error'] ?? null) ? $uploads['error'] : [$uploads['error'] ?? UPLOAD_ERR_NO_FILE];
$sizes = is_array($uploads['size'] ?? null) ? $uploads['size'] : [$uploads['size'] ?? 0];

if (count($names) > 1 && $explicitDate !== '') {
    header('Location: migration_trends.php?error=batch_explicit_date');
    exit;
}

$imported = 0;
$replaced = 0;
$failed = [];
$ignored = 0;
$isBatch = count($names) > 1;
foreach ($names as $index => $name) {
    $name = basename((string) $name);
    if ($isBatch && stripos($name, 'satellite') === false) {
        $ignored++;
        continue;
    }
    $tmp = (string) ($tmpNames[$index] ?? '');
    if (($errors[$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || ($sizes[$index] ?? 0) <= 0 || ($sizes[$index] ?? 0) > 10 * 1024 * 1024
        || !is_uploaded_file($tmp)) {
        $failed[] = $name ?: 'unknown.csv';
        continue;
    }
    try {
        $date = satellite_snapshot_resolve_date($explicitDate, $name);
        $snapshot = satellite_snapshot_read_csv($tmp, $date);
        $result = satellite_snapshot_store($pdo, $name, $date, $snapshot);
        $imported++;
        $replaced += $result['replaced'];
        audit_event('satellite.history_import', [
            'run_id' => $result['run_id'], 'snapshot_date' => $date->format('Y-m-d'),
            'source_filename' => $name, 'hosts' => $snapshot['host_count'],
            'skipped' => $snapshot['skipped_count'], 'replaced' => $result['replaced'],
        ]);
    } catch (Throwable $e) {
        error_log("Satellite history import failed for {$name}: " . $e->getMessage());
        $failed[] = $name;
    }
}

if ($imported === 0) {
    header('Location: migration_trends.php?error=history_import');
    exit;
}
header('Location: migration_trends.php?' . http_build_query([
    'history_success' => $imported,
    'history_replaced' => $replaced,
    'history_failed' => count($failed),
    'history_ignored' => $ignored,
]));
