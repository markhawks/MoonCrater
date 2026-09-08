#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
$configFile = getenv('MOONCRATER_ENV_FILE') ?: (
    is_readable('/etc/mooncrater/apache-env.conf')
        ? '/etc/mooncrater/apache-env.conf'
        : '/etc/mooncreater/apache-env.conf'
);
if (getenv('DB_USER') === false && is_readable($configFile)) {
    $allowed = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'APP_TIMEZONE'];
    foreach (file($configFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $parts = preg_split('/\s+/', trim($line), 3);
        if (($parts[0] ?? '') !== 'SetEnv' || !in_array($parts[1] ?? '', $allowed, true)) continue;
        putenv($parts[1] . '=' . trim((string) ($parts[2] ?? ''), '"'));
    }
}
require __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/Domain/inventory.php';
require_once __DIR__ . '/../app/Domain/satellite_history.php';

$directory = rtrim((string) ($argv[1] ?? ''), DIRECTORY_SEPARATOR);
if ($directory === '' || !is_dir($directory) || !is_readable($directory)) {
    fwrite(STDERR, "Usage: bin/import-satellite-history-dir.php /path/to/csv-directory\n");
    exit(2);
}
$files = glob($directory . DIRECTORY_SEPARATOR . '*.csv') ?: [];
sort($files, SORT_NATURAL | SORT_FLAG_CASE);
$imported = $replaced = $skipped = $failed = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (stripos($name, 'satellite') === false) {
        fwrite(STDOUT, "SKIP  {$name}: not a Satellite export\n");
        $skipped++;
        continue;
    }
    $date = satellite_snapshot_date_from_filename($name);
    if (!$date) {
        fwrite(STDOUT, "SKIP  {$name}: no date in filename\n");
        $skipped++;
        continue;
    }
    try {
        $snapshot = satellite_snapshot_read_csv($file, $date);
        $result = satellite_snapshot_store($pdo, $name, $date, $snapshot);
        $imported++;
        $replaced += $result['replaced'];
        fwrite(STDOUT, "IMPORT {$date->format('Y-m-d')} {$name}: {$snapshot['host_count']} hosts\n");
    } catch (Throwable $e) {
        fwrite(STDERR, "FAIL  {$name}: {$e->getMessage()}\n");
        $failed++;
    }
}
fwrite(STDOUT, "Completed: {$imported} imported, {$replaced} replaced, {$skipped} without date, {$failed} failed.\n");
exit($failed > 0 ? 1 : 0);
