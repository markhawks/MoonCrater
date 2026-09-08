<?php
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Domain/inventory.php';
require_post();
require_admin($pdo);
require_csrf();

$hostname = normalize_hostname($_POST['hostname'] ?? '');
$value = trim((string)($_POST['value'] ?? ''));
$type = (string)($_POST['type'] ?? '');

if (!preg_match('/^[a-z0-9_-]{1,255}$/', $hostname) || !in_array($type, ['date', 'text'], true)) {
    http_response_code(422);
    app_json(['success' => false, 'error' => 'Invalid input']);
}

if ($type === 'text' && strlen($value) > 5000) {
    http_response_code(422);
    app_json(['success' => false, 'error' => 'Note is too long']);
}

if ($type === 'date' && $value !== '') {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        http_response_code(422);
        app_json(['success' => false, 'error' => 'Invalid date']);
    }
}

$column = $type === 'date' ? 'migration_date' : 'notes';
try {
    if ($type === 'date' && $value === '') {
        $stmt = $pdo->prepare("INSERT INTO server_notes (hostname, {$column}) VALUES (?, NULL)
            ON CONFLICT (hostname) DO UPDATE SET {$column} = NULL");
        $stmt->execute([$hostname]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO server_notes (hostname, {$column}) VALUES (?, ?)
            ON CONFLICT (hostname) DO UPDATE SET {$column} = EXCLUDED.{$column}");
        $stmt->execute([$hostname, $value]);
    }
    audit_event('note.update', ['hostname' => $hostname, 'type' => $type]);
    app_json(['success' => true]);
} catch (Throwable $e) {
    error_log('Save note failed: ' . $e->getMessage());
    http_response_code(500);
    app_json(['success' => false, 'error' => 'Operation failed']);
}
