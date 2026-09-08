<?php
declare(strict_types=1);

$isCli = PHP_SAPI === 'cli';
if ($isCli) {
    require __DIR__ . '/../app/config.php';
} else {
    require __DIR__ . '/../app/bootstrap.php';
    require_once __DIR__ . '/../app/Domain/inventory.php';
    require_post();
    require_admin($pdo);
    require_csrf();
}

if ($isCli) {
    require_once __DIR__ . '/../app/Domain/inventory.php';
    $file = $argv[1] ?? (__DIR__ . '/../satellite-import-csv/export_satellite_completo.csv');
    if (!is_file($file) || !is_readable($file)) {
        fwrite(STDERR, "Satellite CSV not found or unreadable.\n");
        exit(1);
    }
} else {
    $upload = $_FILES['satellite_csv'] ?? [];
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || ($upload['size'] ?? 0) <= 0
        || ($upload['size'] ?? 0) > 10 * 1024 * 1024
        || !is_uploaded_file($upload['tmp_name'] ?? '')) {
        http_response_code(400);
        exit("Invalid upload.\n");
    }
    $file = $upload['tmp_name'];
}
$sourceFilename = $isCli ? basename($file) : basename((string)($upload['name'] ?? 'satellite.csv'));

$handle = fopen($file, 'rb');
if ($handle === false) {
    http_response_code(400);
    exit("Cannot open CSV.\n");
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

    $required = ['hostname', 'os', 'ip', 'kernel', 'content_view_environment', 'location', 'last_checkin'];
    foreach ($required as $column) {
        if (!array_key_exists($column, $columns)) throw new RuntimeException("Missing CSV column: {$column}");
    }
    $statusColumn = $columns['status'] ?? null;

    $pdo->beginTransaction();
    $pdo->exec('CREATE TEMP TABLE imported_satellite_hosts (hostname TEXT PRIMARY KEY) ON COMMIT DROP');
    $stageStmt = $pdo->prepare('INSERT INTO imported_satellite_hosts (hostname) VALUES (?) ON CONFLICT DO NOTHING');
    $upsertStmt = $pdo->prepare("
        INSERT INTO inventory_satellite
            (hostname, os, ip, kernel, content_view_environment, location, last_checkin, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT (hostname) DO UPDATE SET
            os = EXCLUDED.os,
            ip = EXCLUDED.ip,
            kernel = EXCLUDED.kernel,
            content_view_environment = EXCLUDED.content_view_environment,
            location = EXCLUDED.location,
            last_checkin = EXCLUDED.last_checkin,
            status = CASE
                WHEN inventory_satellite.status = 'excluded' THEN 'excluded'
                WHEN EXCLUDED.status = 'active' THEN 'active'
                ELSE 'unhealthy'
            END
        WHERE COALESCE(inventory_satellite.role, '') NOT IN ('satellite', 'capsule')
    ");

    $importCount = 0;
    $skippedCount = 0;
    $snapshotHosts = [];
    $lineNumber = 1;
    while (($data = fgetcsv($handle, 65536, $separator, '"', '\\')) !== false) {
        $lineNumber++;
        $hostname = strtolower(trim((string)($data[$columns['hostname']] ?? '')));
        if (!is_valid_hostname($hostname)) {
            $skippedCount++;
            error_log("Satellite import skipped invalid hostname at line {$lineNumber}");
            continue;
        }

        $lastCheckin = trim((string)($data[$columns['last_checkin']] ?? 'N/A'));
        $rawStatus = $statusColumn !== null ? (string)($data[$statusColumn] ?? '') : null;
        $sourceIsActive = normalize_satellite_status($rawStatus, $statusColumn !== null) === 'active';
        $csvStatus = $sourceIsActive ? satellite_status_from_checkin($lastCheckin) : 'unhealthy';

        $os = trim((string)($data[$columns['os']] ?? 'N/A'));
        $snapshotHosts[$hostname] = null;
        if ($csvStatus === 'active' && preg_match('/\b(?:Red Hat Enterprise Linux|RedHat|RHEL(?: Server)?)\D*(10|[789])(?:\.(\d+))?/i', $os, $versionMatch)) {
            $snapshotHosts[$hostname] = $versionMatch[1] . (isset($versionMatch[2]) && $versionMatch[2] !== '' ? '.' . $versionMatch[2] : '');
        }

        $stageStmt->execute([$hostname]);
        $upsertStmt->execute([
            $hostname,
            $os,
            trim((string)($data[$columns['ip']] ?? 'N/A')),
            trim((string)($data[$columns['kernel']] ?? 'N/A')),
            trim((string)($data[$columns['content_view_environment']] ?? 'N/A')),
            trim((string)($data[$columns['location']] ?? 'N/A')),
            $lastCheckin,
            $csvStatus,
        ]);
        $importCount++;
    }

    $missingCount = $pdo->exec("
        UPDATE inventory_satellite i
        SET status = 'missing'
        WHERE i.status IN ('active', 'missing', 'unhealthy')
          AND COALESCE(i.role, '') NOT IN ('satellite', 'capsule')
          AND NOT EXISTS (
              SELECT 1 FROM imported_satellite_hosts n WHERE n.hostname = i.hostname
          )
    ");

    $runStmt = $pdo->prepare("
        INSERT INTO satellite_import_runs
            (source_filename, imported_count, skipped_count, missing_count, has_status_column)
        VALUES (?, ?, ?, ?, ?)
        RETURNING id
    ");
    $runStmt->bindValue(1, $sourceFilename, PDO::PARAM_STR);
    $runStmt->bindValue(2, $importCount, PDO::PARAM_INT);
    $runStmt->bindValue(3, $skippedCount, PDO::PARAM_INT);
    $runStmt->bindValue(4, $missingCount, PDO::PARAM_INT);
    $runStmt->bindValue(5, $statusColumn !== null, PDO::PARAM_BOOL);
    $runStmt->execute();
    $runId = (int)$runStmt->fetchColumn();
    $snapshotCounts = [];
    foreach ($snapshotHosts as $version) {
        if ($version !== null) $snapshotCounts[$version] = ($snapshotCounts[$version] ?? 0) + 1;
    }
    $snapshotStmt = $pdo->prepare('INSERT INTO satellite_os_snapshots (run_id, os_major, os_version, host_count) VALUES (?, ?, ?, ?)');
    foreach ($snapshotCounts as $version => $count) {
        $snapshotStmt->execute([$runId, explode('.', $version, 2)[0], $version, $count]);
    }

    $pdo->commit();
    fclose($handle);

    if (!$isCli) {
        audit_event('satellite.import', [
            'imported' => $importCount,
            'skipped' => $skippedCount,
            'missing' => $missingCount,
            'has_status_column' => $statusColumn !== null,
            'run_id' => $runId,
        ]);
        header('Location: index.php?' . http_build_query([
            'import_success' => $importCount,
            'import_skipped' => $skippedCount,
        ]));
        exit;
    }

    echo "Imported {$importCount}; skipped {$skippedCount}; absent/missing {$missingCount}.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (is_resource($handle)) fclose($handle);
    error_log('Satellite import failed: ' . $e->getMessage());
    http_response_code(500);
    exit("Satellite import failed. See server log.\n");
}
