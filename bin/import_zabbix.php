<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}
require __DIR__ . '/../app/config.php';

$csvFile = $argv[1] ?? (__DIR__ . '/../satellite-import-csv/zabbix_hosts.csv');
if (!is_file($csvFile) || !is_readable($csvFile)) {
    fwrite(STDERR, "Zabbix CSV not found or unreadable.\n");
    exit(1);
}

$handle = fopen($csvFile, 'rb');
if ($handle === false) {
    fwrite(STDERR, "Cannot open Zabbix CSV.\n");
    exit(1);
}

try {
    $pdo->beginTransaction();
    $pdo->exec('TRUNCATE TABLE zabbix_hosts');
    fgetcsv($handle, 1000, ';', '"', '\\');
    $stmt = $pdo->prepare('INSERT INTO zabbix_hosts (hostname, role, last_update) VALUES (?, ?, ?)');
    $count = 0;
    while (($data = fgetcsv($handle, 1000, ';', '"', '\\')) !== false) {
        if (count($data) < 3) continue;
        $hostname = strtolower(trim($data[0]));
        $role = trim($data[1]);
        $lastUpdate = trim($data[2]);
        if ($hostname === '') continue;
        if (!in_array($role, ['Core Server', 'Proxy'], true)) $role = 'Core Server';
        $stmt->execute([$hostname, $role, $lastUpdate]);
        $count++;
    }
    $pdo->commit();
    fclose($handle);
    echo "Imported {$count} Zabbix hosts.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fclose($handle);
    error_log('Zabbix import failed: ' . $e->getMessage());
    fwrite(STDERR, "Zabbix import failed. See server log.\n");
    exit(1);
}
