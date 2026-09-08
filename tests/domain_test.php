<?php
declare(strict_types=1);
require __DIR__ . '/../app/Domain/inventory.php';

$cases = [
    [normalize_hostname(' Server01.Example.COM '), 'server01'],
    [normalize_hostname('192.0.2.10'), '192.0.2.10'],
    [detect_os_major('Red Hat Enterprise Linux 10.1'), '10'],
    [detect_os_major('RHEL 8.10'), '8'],
];
foreach ($cases as [$actual, $expected]) {
    if ($actual !== $expected) {
        fwrite(STDERR, "Expected {$expected}, got {$actual}\n");
        exit(1);
    }
}
if (!is_valid_hostname('server-01.example.com')) exit(1);
if (!is_valid_hostname('192.0.2.10')) exit(1);
if (is_valid_hostname('<script>alert(1)</script>')) exit(1);
if (is_valid_hostname('-invalid.example')) exit(1);
if (normalize_satellite_status(null, false) !== 'active') exit(1);
if (normalize_satellite_status('ACTIVE', true) !== 'active') exit(1);
if (normalize_satellite_status('enabled', true) !== 'active') exit(1);
if (normalize_satellite_status('inactive', true) !== 'missing') exit(1);
if (normalize_satellite_status('', true) !== 'missing') exit(1);
echo "Domain helper tests passed.\n";
