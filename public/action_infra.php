<?php
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Domain/inventory.php';
require_post();
require_admin($pdo);
require_csrf();

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$hostname = strtolower(trim($data['hostname'] ?? ''));
$field = trim($data['field'] ?? '');
$value = trim($data['value'] ?? '');
$allowed = ['loc' => 'location', 'lc' => 'last_checkin'];

if (!is_valid_hostname($hostname) || ($field !== 'hostname' && !isset($allowed[$field]))) {
    http_response_code(422);
    app_json(['success' => false, 'error' => 'Invalid input']);
}

try {
    if ($field === 'hostname') {
        $newHostname = strtolower($value);
        if (!is_valid_hostname($newHostname)) {
            http_response_code(422);
            app_json(['success' => false, 'error' => 'Invalid hostname']);
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT hostname, role FROM inventory_satellite WHERE LOWER(TRIM(hostname)) IN (?, ?) FOR UPDATE");
        $stmt->execute([$hostname, $newHostname]);
        $current = $target = null;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $record) {
            $key = strtolower(trim($record['hostname']));
            if ($key === $hostname) $current = $record;
            if ($key === $newHostname) $target = $record;
        }
        if (!$current || !in_array($current['role'], ['satellite', 'capsule'], true)) {
            throw new RuntimeException('Infrastructure host not found');
        }
        if (!$target) {
            $pdo->rollBack();
            http_response_code(404);
            app_json(['success' => false, 'error' => 'Hostname not found in the imported Satellite inventory']);
        }
        if (in_array($target['role'], ['satellite', 'capsule'], true) && $target['role'] !== $current['role']) {
            $pdo->rollBack();
            http_response_code(409);
            app_json(['success' => false, 'error' => 'Hostname is already assigned to another infrastructure role']);
        }
        if ($hostname !== $newHostname) {
            $stmt = $pdo->prepare("UPDATE inventory_satellite SET role = 'host' WHERE LOWER(TRIM(hostname)) = ?");
            $stmt->execute([$hostname]);
            $stmt = $pdo->prepare('UPDATE inventory_satellite SET role = ? WHERE LOWER(TRIM(hostname)) = ?');
            $stmt->execute([$current['role'], $newHostname]);
        }
        $pdo->commit();
        audit_event('infrastructure.hostname.update', ['old_hostname' => $hostname, 'new_hostname' => $newHostname, 'role' => $current['role']]);
        app_json(['success' => true]);
    }

    $stmt = $pdo->prepare("UPDATE inventory_satellite SET {$allowed[$field]} = ? WHERE LOWER(TRIM(hostname)) = ?");
    $stmt->execute([$value === '' ? null : $value, $hostname]);
    audit_event('infrastructure.update', ['hostname' => $hostname, 'field' => $field]);
    app_json(['success' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Infrastructure update failed: ' . $e->getMessage());
    http_response_code(500);
    app_json(['success' => false, 'error' => 'Operation failed']);
}
