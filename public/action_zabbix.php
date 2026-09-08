<?php
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Domain/inventory.php';
require_post();
require_admin($pdo);
require_csrf();

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$action = trim($data['action'] ?? '');

try {
    if ($action === 'add') {
        $hostname = strtolower(trim($data['hostname'] ?? ''));
        $role = trim($data['role'] ?? 'Core Server');
        if (!is_valid_hostname($hostname)) { http_response_code(422); app_json(['success' => false, 'error' => 'Hostname is required']); }
        if (!in_array($role, ['Core Server', 'Proxy'], true)) $role = 'Core Server';
        $stmt = $pdo->prepare('INSERT INTO zabbix_hosts (hostname, role, last_update) VALUES (?, ?, ?) ON CONFLICT (hostname) DO NOTHING');
        $stmt->execute([$hostname, $role, date('Y-m-d')]);
        if ($stmt->rowCount() === 0) { http_response_code(409); app_json(['success' => false, 'error' => 'Hostname already exists']); }
        $sat = satellite_data($pdo, $hostname);
        audit_event('zabbix.add', ['hostname' => $hostname, 'role' => $role]);
        app_json(['success' => true, 'hostname' => $hostname, 'role' => $role, 'last_update' => date('Y-m-d'), 'sat' => $sat]);
    }

    if ($action === 'update') {
        $old = strtolower(trim($data['old_hostname'] ?? ''));
        $new = strtolower(trim($data['new_hostname'] ?? ''));
        $role = trim($data['role'] ?? '');
        if (!is_valid_hostname($old) || !is_valid_hostname($new)) { http_response_code(422); app_json(['success' => false, 'error' => 'Hostname is required']); }
        if (!in_array($role, ['Core Server', 'Proxy'], true)) $role = 'Core Server';
        $stmt = $pdo->prepare('UPDATE zabbix_hosts SET hostname = ?, role = ?, last_update = ? WHERE hostname = ?');
        $stmt->execute([$new, $role, date('Y-m-d'), $old]);
        if ($stmt->rowCount() === 0) { http_response_code(404); app_json(['success' => false, 'error' => 'Host not found']); }
        audit_event('zabbix.update', ['old_hostname' => $old, 'hostname' => $new, 'role' => $role]);
        app_json(['success' => true, 'old_hostname' => $old, 'new_hostname' => $new, 'role' => $role, 'sat' => satellite_data($pdo, $new)]);
    }

    if ($action === 'delete') {
        $hostname = strtolower(trim($data['hostname'] ?? ''));
        if (!is_valid_hostname($hostname)) { http_response_code(422); app_json(['success' => false, 'error' => 'Hostname is required']); }
        $stmt = $pdo->prepare('DELETE FROM zabbix_hosts WHERE hostname = ?');
        $stmt->execute([$hostname]);
        audit_event('zabbix.delete', ['hostname' => $hostname]);
        app_json(['success' => true, 'hostname' => $hostname]);
    }

    if ($action === 'update_zver') {
        $hostname = strtolower(trim($data['hostname'] ?? ''));
        $version = trim($data['zabbix_version'] ?? '');
        if (!is_valid_hostname($hostname)) { http_response_code(422); app_json(['success' => false, 'error' => 'Hostname is required']); }
        $stmt = $pdo->prepare('UPDATE zabbix_hosts SET zabbix_version = ? WHERE hostname = ?');
        $stmt->execute([$version === '' ? null : $version, $hostname]);
        audit_event('zabbix.version', ['hostname' => $hostname, 'version' => $version]);
        app_json(['success' => true, 'hostname' => $hostname, 'zabbix_version' => $version === '' ? null : $version]);
    }

    http_response_code(400);
    app_json(['success' => false, 'error' => 'Unknown action']);
} catch (Throwable $e) {
    error_log('Zabbix update failed: ' . $e->getMessage());
    http_response_code(500);
    app_json(['success' => false, 'error' => 'Operation failed']);
}

function satellite_data(PDO $pdo, string $hostname): array
{
    $stmt = $pdo->prepare("SELECT hostname, os, ip, kernel, content_view_environment, location, last_checkin
        FROM inventory_satellite
        WHERE LOWER(TRIM(hostname)) = LOWER(TRIM(?)) AND status = 'active'
        LIMIT 1");
    $stmt->execute([$hostname]);
    return $stmt->fetch() ?: [];
}
