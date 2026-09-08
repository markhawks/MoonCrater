<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Domain/inventory.php';
require_post();
$currentUser = require_admin($pdo);
require_csrf();

$upload = $_FILES['satellite_csv'] ?? [];
$importDate = trim((string)($_POST['import_date'] ?? ''));
$date = DateTimeImmutable::createFromFormat('!Y-m-d', $importDate);
if (!$date || $date->format('Y-m-d') !== $importDate) {
    header('Location: migration_trends.php?error=invalid_date');
    exit;
}
$timezone = new DateTimeZone(date_default_timezone_get());
$importedAt = new DateTimeImmutable($importDate . ' 12:00:00', $timezone);
$snapshotReference = new DateTimeImmutable($importDate . ' 23:59:59', $timezone);
if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
    || ($upload['size'] ?? 0) <= 0
    || ($upload['size'] ?? 0) > 10 * 1024 * 1024
    || !is_uploaded_file($upload['tmp_name'] ?? '')) {
    header('Location: migration_trends.php?error=invalid_file');
    exit;
}

$handle = fopen($upload['tmp_name'], 'rb');
if ($handle === false) {
    header('Location: migration_trends.php?error=invalid_file');
    exit;
}

try {
    $firstLine = fgets($handle);
    if ($firstLine === false) throw new RuntimeException('Empty CSV');
    $separator = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
    rewind($handle);
    $header = fgetcsv($handle, 65536, $separator, '"', '\\');
    if (!is_array($header)) throw new RuntimeException('Missing CSV header');
    $header = array_map(static fn($name) => strtolower(trim((string)$name)), $header);
    if (isset($header[0])) $header[0] = ltrim($header[0], "\xEF\xBB\xBF");
    $columns = array_flip($header);
    foreach (['hostname', 'os', 'last_checkin'] as $required) {
        if (!array_key_exists($required, $columns)) throw new RuntimeException("Missing CSV column: {$required}");
    }

    $hosts = [];
    $skipped = 0;
    while (($row = fgetcsv($handle, 65536, $separator, '"', '\\')) !== false) {
        $hostname = strtolower(trim((string)($row[$columns['hostname']] ?? '')));
        if (!is_valid_hostname($hostname)) {
            $skipped++;
            continue;
        }
        $lastCheckin = trim((string)($row[$columns['last_checkin']] ?? ''));
        if (satellite_status_from_checkin($lastCheckin, $snapshotReference) !== 'active') {
            $hosts[$hostname] = null;
            continue;
        }
        $os = trim((string)($row[$columns['os']] ?? ''));
        if (!preg_match('/\b(?:Red Hat Enterprise Linux|RedHat|RHEL(?: Server)?)\D*(10|[789])(?:\.(\d+))?/i', $os, $match)) {
            $hosts[$hostname] = null;
            continue;
        }
        $hosts[$hostname] = $match[1] . (isset($match[2]) && $match[2] !== '' ? '.' . $match[2] : '');
    }
    fclose($handle);

    $counts = [];
    foreach ($hosts as $version) {
        if ($version !== null) $counts[$version] = ($counts[$version] ?? 0) + 1;
    }

    $pdo->beginTransaction();
    $runStmt = $pdo->prepare("
        INSERT INTO satellite_import_runs
            (imported_at, source_filename, imported_count, skipped_count, missing_count, has_status_column)
        VALUES (?, ?, ?, ?, 0, FALSE)
        RETURNING id
    ");
    $runStmt->execute([
        $importedAt->format(DateTimeInterface::ATOM),
        basename((string)($upload['name'] ?? 'satellite.csv')),
        count($hosts),
        $skipped,
    ]);
    $runId = (int)$runStmt->fetchColumn();
    $snapshotStmt = $pdo->prepare("
        INSERT INTO satellite_os_snapshots (run_id, os_major, os_version, host_count)
        VALUES (?, ?, ?, ?)
    ");
    foreach ($counts as $version => $count) {
        $snapshotStmt->execute([$runId, explode('.', $version, 2)[0], $version, $count]);
    }
    $pdo->commit();

    audit_event('satellite.history_import', [
        'run_id' => $runId,
        'import_date' => $importDate,
        'source_filename' => basename((string)($upload['name'] ?? 'satellite.csv')),
        'hosts' => count($hosts),
        'skipped' => $skipped,
    ]);
    header('Location: migration_trends.php?history_success=' . $runId);
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (is_resource($handle)) fclose($handle);
    error_log('Satellite history import failed: ' . $e->getMessage());
    header('Location: migration_trends.php?error=history_import');
    exit;
}
