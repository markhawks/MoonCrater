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
if ($isCli) require_once __DIR__ . '/../app/Domain/inventory.php';

if ($isCli) {
    $file = $argv[1] ?? '';
    if ($file === '' || !is_file($file) || !is_readable($file)) {
        fwrite(STDERR, "Usage: public/import_ivanti.php /path/to/ivanti.csv\n");
        exit(1);
    }
} else {
    $upload = $_FILES['ivanti_csv'] ?? [];
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || ($upload['size'] ?? 0) <= 0
        || ($upload['size'] ?? 0) > 10 * 1024 * 1024
        || !is_uploaded_file($upload['tmp_name'] ?? '')) {
        http_response_code(400);
        exit("Invalid Ivanti upload.\n");
    }
    $file = $upload['tmp_name'];
}
$sourceFilename = $isCli ? basename($file) : basename((string) ($upload['name'] ?? 'ivanti.csv'));
$handle = fopen($file, 'rb');
if ($handle === false) {
    http_response_code(400);
    exit("Cannot open Ivanti CSV.\n");
}

try {
    $firstLine = fgets($handle);
    if ($firstLine === false) throw new RuntimeException('Empty CSV');
    $separator = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
    rewind($handle);

    $header = fgetcsv($handle, 65536, $separator, '"', '\\');
    if (!is_array($header)) throw new RuntimeException('Missing CSV header');
    $header = array_map(static function ($name): string {
        $normalized = strtolower(ltrim(trim((string) $name), "\xEF\xBB\xBF"));
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $normalized), '_');
    }, $header);
    $columns = array_flip($header);

    $aliases = [
        'hostname' => ['device_name', 'hostname'],
        'os' => ['os_name', 'os'],
        'ip' => ['address', 'ip'],
        'scan_date' => ['last_hardware_scan_date', 'scan_date'],
    ];
    $resolved = [];
    foreach ($aliases as $target => $names) {
        foreach ($names as $name) {
            if (array_key_exists($name, $columns)) {
                $resolved[$target] = $columns[$name];
                break;
            }
        }
        if (!array_key_exists($target, $resolved)) {
            throw new RuntimeException("Missing Ivanti CSV column for: {$target}");
        }
    }

    $pdo->beginTransaction();
    $updateStmt = $pdo->prepare("
        UPDATE inventory_ivanti
        SET hostname = ?, os = ?, ip = ?, scan_date = ?,
            status = CASE WHEN status IN ('excluded', 'decommissioned') THEN status ELSE 'active' END
        WHERE LOWER(TRIM(hostname)) = ?
        RETURNING id
    ");
    $insertStmt = $pdo->prepare("
        INSERT INTO inventory_ivanti (hostname, os, ip, scan_date, status)
        VALUES (?, ?, ?, ?, 'active')
    ");

    $seen = [];
    $importedCount = 0;
    $insertedCount = 0;
    $updatedCount = 0;
    $skippedCount = 0;
    while (($data = fgetcsv($handle, 65536, $separator, '"', '\\')) !== false) {
        $hostname = strtolower(trim((string) ($data[$resolved['hostname']] ?? '')));
        if (!is_valid_hostname($hostname) || isset($seen[$hostname])) {
            $skippedCount++;
            continue;
        }

        $scanDateRaw = trim((string) ($data[$resolved['scan_date']] ?? ''));
        $scanDate = DateTimeImmutable::createFromFormat('!d/m/Y H:i', $scanDateRaw)
            ?: DateTimeImmutable::createFromFormat('!d/m/Y H:i:s', $scanDateRaw);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (!$scanDate || (is_array($dateErrors) && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
            $skippedCount++;
            continue;
        }

        $os = trim((string) ($data[$resolved['os']] ?? '')) ?: 'N/A';
        $ip = trim((string) ($data[$resolved['ip']] ?? '')) ?: 'N/A';
        $scanDateValue = $scanDate->format('d/m/Y H:i');
        $updateStmt->execute([$hostname, $os, $ip, $scanDateValue, $hostname]);
        if ($updateStmt->fetchColumn() !== false) {
            $updatedCount++;
        } else {
            $insertStmt->execute([$hostname, $os, $ip, $scanDateValue]);
            $insertedCount++;
        }
        $seen[$hostname] = true;
        $importedCount++;
    }

    $runStmt = $pdo->prepare("
        INSERT INTO ivanti_import_runs
            (source_filename, imported_count, inserted_count, updated_count, skipped_count)
        VALUES (?, ?, ?, ?, ?)
    ");
    $runStmt->execute([$sourceFilename, $importedCount, $insertedCount, $updatedCount, $skippedCount]);
    $pdo->commit();
    fclose($handle);

    if (!$isCli) {
        audit_event('ivanti.import', [
            'source_filename' => $sourceFilename,
            'imported' => $importedCount,
            'inserted' => $insertedCount,
            'updated' => $updatedCount,
            'skipped' => $skippedCount,
        ]);
        header('Location: index.php?' . http_build_query([
            'ivanti_success' => $importedCount,
            'ivanti_inserted' => $insertedCount,
            'ivanti_updated' => $updatedCount,
            'ivanti_skipped' => $skippedCount,
        ]));
        exit;
    }

    echo "Imported {$importedCount}; inserted {$insertedCount}; updated {$updatedCount}; skipped {$skippedCount}.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (is_resource($handle)) fclose($handle);
    error_log('Ivanti import failed: ' . $e->getMessage());
    http_response_code(500);
    $message = "Ivanti import failed. See server log.\n";
    if ($isCli) fwrite(STDERR, $message); else echo $message;
    exit(1);
}
