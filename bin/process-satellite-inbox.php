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

$directory = rtrim((string) ($argv[1] ?? (__DIR__ . '/../satellite-import-csv')), DIRECTORY_SEPARATOR);
if ($directory === '' || !is_dir($directory) || !is_readable($directory)) {
    fwrite(STDERR, "Satellite inbox not found or unreadable: {$directory}\n");
    exit(2);
}

$lock = fopen(__DIR__ . '/../var/satellite-inbox.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "Another Satellite inbox import is already running.\n");
    exit(0);
}

$candidates = [];
foreach (glob($directory . DIRECTORY_SEPARATOR . '*.csv') ?: [] as $file) {
    $name = basename($file);
    if (stripos($name, 'satellite') === false) continue;
    $timestamp = satellite_snapshot_datetime_from_filename($name);
    if (!$timestamp) {
        fwrite(STDERR, "SKIP  {$name}: timestamp not found in filename\n");
        continue;
    }
    if ($timestamp > new DateTimeImmutable('now')) {
        fwrite(STDERR, "SKIP  {$name}: timestamp is in the future\n");
        continue;
    }
    $candidates[] = ['file' => $file, 'name' => $name, 'timestamp' => $timestamp];
}

usort($candidates, static function (array $left, array $right): int {
    $timeOrder = $left['timestamp'] <=> $right['timestamp'];
    return $timeOrder !== 0 ? $timeOrder : strnatcasecmp($left['name'], $right['name']);
});
if (!$candidates) {
    fwrite(STDOUT, "No Satellite CSV files ready for import in {$directory}.\n");
    exit(0);
}

$known = [];
foreach ($pdo->query('SELECT source_filename, is_current FROM satellite_import_runs')->fetchAll() as $run) {
    $currentValue = strtolower((string) $run['is_current']);
    $known[(string) $run['source_filename']] = in_array($currentValue, ['1', 't', 'true'], true);
}
$latestIndex = array_key_last($candidates);
$historyImported = $operationalImported = $skipped = $failed = 0;

foreach ($candidates as $index => $candidate) {
    $alreadyImported = array_key_exists($candidate['name'], $known);
    if ($alreadyImported && ($index !== $latestIndex || $known[$candidate['name']] === true)) {
        $skipped++;
        continue;
    }
    try {
        if ($index !== $latestIndex) {
            $snapshot = satellite_snapshot_read_csv($candidate['file'], $candidate['timestamp']);
            satellite_snapshot_store($pdo, $candidate['name'], $candidate['timestamp'], $snapshot);
            fwrite(STDOUT, "HISTORY {$candidate['timestamp']->format('Y-m-d H:i')} {$candidate['name']}: {$snapshot['host_count']} hosts\n");
            $historyImported++;
            continue;
        }

        $command = [PHP_BINARY, __DIR__ . '/../public/import_satellite.php', $candidate['file']];
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Could not start the operational importer');
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) throw new RuntimeException(trim($stderr . ' ' . $stdout));
        fwrite(STDOUT, "CURRENT {$candidate['timestamp']->format('Y-m-d H:i')} {$candidate['name']}: " . trim($stdout) . "\n");
        $operationalImported++;
    } catch (Throwable $e) {
        fwrite(STDERR, "FAIL  {$candidate['name']}: {$e->getMessage()}\n");
        $failed++;
    }
}

fwrite(STDOUT, "Completed: {$historyImported} historical, {$operationalImported} current, {$skipped} already imported, {$failed} failed.\n");
exit($failed > 0 ? 1 : 0);
