#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../app/config.php';

$username = trim($argv[1] ?? '');
if ($username === '') {
    fwrite(STDERR, "Usage: bin/create-admin.php USERNAME\nPassword is read from MOONCREATER_ADMIN_PASSWORD or requested interactively.\n");
    exit(2);
}
$password = getenv('MOONCREATER_ADMIN_PASSWORD') ?: '';
if ($password === '') {
    fwrite(STDOUT, 'Password: ');
    if (function_exists('shell_exec')) shell_exec('stty -echo');
    $password = trim((string) fgets(STDIN));
    if (function_exists('shell_exec')) shell_exec('stty echo');
    fwrite(STDOUT, "\n");
}
if (strlen($password) < 8) {
    fwrite(STDERR, "Password must contain at least 8 characters.\n");
    exit(2);
}
$stmt = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'admin') ON CONFLICT (username) DO UPDATE SET password = EXCLUDED.password, role = 'admin', last_password_reset_at = CURRENT_TIMESTAMP");
$stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
echo "Administrator created or updated.\n";
