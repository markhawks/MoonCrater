<?php
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Domain/inventory.php';
require_post();
require_admin($pdo);
require_csrf();

$hostname = strtolower(trim($_POST['hostname'] ?? ''));
$action = $_POST['action'] ?? '';
if (is_valid_hostname($hostname) && in_array($action, ['exclude', 'activate'], true)) {
    $status = $action === 'activate' ? 'active' : 'excluded';
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('UPDATE inventory_ivanti SET status = ? WHERE hostname = ?');
        $stmt->execute([$status, $hostname]);
        $stmt = $pdo->prepare('UPDATE inventory_satellite SET status = ? WHERE hostname = ?');
        $stmt->execute([$status, $hostname]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Host action failed: ' . $e->getMessage());
        http_response_code(500);
        exit('Operation failed.');
    }
}
safe_local_redirect();
