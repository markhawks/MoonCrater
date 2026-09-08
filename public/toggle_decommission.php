<?php
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Domain/inventory.php';
require_post();
require_admin($pdo);
require_csrf();

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$hostname = strtolower(trim($data['hostname'] ?? ''));
if (!is_valid_hostname($hostname)) {
    http_response_code(422);
    app_json(['success' => false, 'error' => 'Missing hostname']);
}

try {
    $stmt = $pdo->prepare('SELECT status FROM inventory_ivanti WHERE hostname = ?');
    $stmt->execute([$hostname]);
    $currentStatus = $stmt->fetchColumn();
    if ($currentStatus === false) {
        $stmt = $pdo->prepare('SELECT status FROM inventory_satellite WHERE hostname = ?');
        $stmt->execute([$hostname]);
        $currentStatus = $stmt->fetchColumn();
    }
    if ($currentStatus === false) {
        http_response_code(404);
        app_json(['success' => false, 'error' => 'Host not found']);
    }

    $newStatus = $currentStatus === 'excluded' ? 'active' : 'excluded';
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('UPDATE inventory_ivanti SET status = ? WHERE hostname = ?');
    $stmt->execute([$newStatus, $hostname]);
    $stmt = $pdo->prepare('UPDATE inventory_satellite SET status = ? WHERE hostname = ?');
    $stmt->execute([$newStatus, $hostname]);
    $pdo->commit();
    audit_event('host.status', ['hostname' => $hostname, 'status' => $newStatus]);
    app_json(['success' => true, 'new_status' => $newStatus]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Host status update failed: ' . $e->getMessage());
    http_response_code(500);
    app_json(['success' => false, 'error' => 'Operation failed']);
}
