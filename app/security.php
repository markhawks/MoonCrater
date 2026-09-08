<?php
declare(strict_types=1);

const SESSION_IDLE_TIMEOUT = 1800;

function app_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function app_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => app_is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();

    $now = time();
    if (isset($_SESSION['last_activity']) && $now - (int) $_SESSION['last_activity'] > SESSION_IDLE_TIMEOUT) {
        $_SESSION = [];
        session_destroy();
        session_start();
    }
    $_SESSION['last_activity'] = $now;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

function request_csrf_token(): string
{
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return is_string($header) && $header !== '' ? $header : (string) ($_POST['csrf_token'] ?? '');
}

function require_csrf(): void
{
    $expected = $_SESSION['csrf_token'] ?? '';
    if (!is_string($expected) || $expected === '' || !hash_equals($expected, request_csrf_token())) {
        http_response_code(403);
        app_json(['success' => false, 'error' => 'Invalid CSRF token']);
    }
}

function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        http_response_code(405);
        app_json(['success' => false, 'error' => 'Method not allowed']);
    }
}

function app_wants_json(): bool
{
    return str_contains(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')
        || str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
}

function app_json(array $payload): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function require_login(PDO $pdo): array
{
    if (empty($_SESSION['user_id'])) {
        if (app_wants_json()) {
            http_response_code(401);
            app_json(['success' => false, 'error' => 'Unauthorized']);
        }
        header('Location: login.php');
        exit;
    }

    $stmt = $pdo->prepare('SELECT id, username, role FROM users WHERE id = ?');
    $stmt->execute([(int) $_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        $_SESSION = [];
        session_destroy();
        header('Location: login.php');
        exit;
    }
    return $user;
}

function require_admin(PDO $pdo): array
{
    $user = require_login($pdo);
    if (($user['role'] ?? 'user') !== 'admin') {
        http_response_code(403);
        if (app_wants_json()) app_json(['success' => false, 'error' => 'Forbidden']);
        header('Location: index.php?error=access_denied');
        exit;
    }
    return $user;
}

function public_error(Throwable $e, string $context): string
{
    error_log($context . ': ' . $e->getMessage());
    return 'Operation failed. Contact the administrator.';
}

function safe_local_redirect(string $fallback = 'index.php'): never
{
    $target = $_SERVER['HTTP_REFERER'] ?? '';
    $parts = parse_url($target);
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($target === '' || $parts === false || (isset($parts['host']) && strcasecmp($parts['host'], $host) !== 0)) {
        $target = $fallback;
    }
    header('Location: ' . $target);
    exit;
}

function audit_event(string $action, array $details = []): void
{
    foreach (['password', 'new_password', 'new_pwd', 'csrf_token'] as $secret) unset($details[$secret]);
    $event = [
        'event' => $action,
        'user_id' => $_SESSION['user_id'] ?? null,
        'username' => $_SESSION['username'] ?? null,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        'time' => gmdate('c'),
        'details' => $details,
    ];
    error_log('AUDIT ' . json_encode($event, JSON_UNESCAPED_SLASHES));
}
