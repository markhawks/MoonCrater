<?php
declare(strict_types=1);

require_once __DIR__ . '/version.php';
require_once __DIR__ . '/paths.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Europe/Rome');

$appName = getenv('APP_NAME') ?: 'MoonCreater';
$appSubtitle = getenv('APP_SUBTITLE') ?: 'Infrastructure Control Plane Patching';
$appVersion = APP_VERSION;
$customerName = getenv('CUSTOMER_NAME') ?: 'Acme Corporation';
$customerLogoCandidate = getenv('CUSTOMER_LOGO') ?: 'assets/img/customer-default.svg';
$customerLogo = preg_match('#^/?[a-zA-Z0-9][a-zA-Z0-9_./-]*$#', $customerLogoCandidate)
    ? $customerLogoCandidate
    : 'assets/img/customer-default.svg';

function required_env(string $name): string
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        throw new RuntimeException("Missing required environment variable: {$name}");
    }
    return $value;
}

try {
    $dbHost = getenv('DB_HOST') ?: 'localhost';
    $dbPort = getenv('DB_PORT') ?: '5432';
    $dbName = getenv('DB_NAME') ?: 'patching';
    $dbUser = required_env('DB_USER');
    $dbPass = required_env('DB_PASS');
    $pdo = new PDO(
        "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName}",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (Throwable $e) {
    error_log('Database bootstrap failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Application configuration error. Contact the administrator.');
}

if (!defined('APP_MIGRATING')) {
    try {
        $storedCustomerLogo = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key = 'customer_logo_data'")->fetchColumn();
        if ($storedCustomerLogo === 'image/customer-default.svg') $storedCustomerLogo = 'assets/img/customer-default.svg';
        if (is_string($storedCustomerLogo) && ($storedCustomerLogo === 'assets/img/customer-default.svg' || preg_match('#^data:image/(?:png|jpeg|webp);base64,[A-Za-z0-9+/=]+$#', $storedCustomerLogo))) {
            $customerLogo = $storedCustomerLogo;
        }
    } catch (PDOException $e) {
        error_log('Application settings unavailable; run database migrations: ' . $e->getMessage());
    }
}
