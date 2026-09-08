#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
define('APP_MIGRATING', true);
require __DIR__ . '/../app/config.php';

$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (filename TEXT PRIMARY KEY, checksum CHAR(64) NOT NULL, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$files = glob(APP_MIGRATIONS_DIR . '/*.sql') ?: [];
sort($files, SORT_NATURAL);
$lookup = $pdo->query('SELECT filename, checksum FROM schema_migrations')->fetchAll(PDO::FETCH_KEY_PAIR);

foreach ($files as $file) {
    $name = basename($file);
    $sql = file_get_contents($file);
    if ($sql === false) throw new RuntimeException("Cannot read migration {$name}");
    $checksum = hash('sha256', $sql);
    if (isset($lookup[$name])) {
        if (!hash_equals((string) $lookup[$name], $checksum)) {
            throw new RuntimeException("Applied migration changed: {$name}");
        }
        echo "SKIP  {$name}\n";
        continue;
    }
    try {
        $pdo->exec($sql);
        $stmt = $pdo->prepare('INSERT INTO schema_migrations (filename, checksum) VALUES (?, ?)');
        $stmt->execute([$name, $checksum]);
        echo "APPLY {$name}\n";
    } catch (Throwable $e) {
        throw $e;
    }
}
