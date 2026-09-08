<?php
declare(strict_types=1);
require __DIR__ . '/../app/Domain/inventory.php';
require __DIR__ . '/../app/Domain/satellite_history.php';

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
$reference = new DateTimeImmutable('2026-09-08 12:00:00 UTC');
if (satellite_status_from_checkin('2026-08-10 12:00:00 UTC', $reference) !== 'active') exit(1);
if (satellite_status_from_checkin('2026-08-08 11:59:59 UTC', $reference) !== 'unhealthy') exit(1);
if (satellite_snapshot_date_from_filename('export_satellite_completo-03092026.csv')?->format('Y-m-d') !== '2026-09-03') exit(1);
if (satellite_snapshot_datetime_from_filename('export_satellite_completo-08092026-2245.csv')?->format('Y-m-d H:i') !== '2026-09-08 22:45') exit(1);
if (satellite_snapshot_date_from_filename('export-2026-07-02.csv')?->format('Y-m-d') !== '2026-07-02') exit(1);
if (satellite_snapshot_date_from_filename('export-without-date.csv') !== null) exit(1);
$snapshotFixture = tmpfile();
if ($snapshotFixture === false) exit(1);
$snapshotPath = stream_get_meta_data($snapshotFixture)['uri'];
fwrite($snapshotFixture, "hostname;os;last_checkin\n");
fwrite($snapshotFixture, "host1.example.test;RHEL 8.10;2026-08-31 12:00:00 UTC\n");
fwrite($snapshotFixture, "host2.example.test;RHEL 9.6;2026-07-01 12:00:00 UTC\n");
fflush($snapshotFixture);
$snapshot = satellite_snapshot_read_csv($snapshotPath, new DateTimeImmutable('2026-09-03'));
if ($snapshot['host_count'] !== 2 || ($snapshot['counts']['8.10'] ?? 0) !== 1 || isset($snapshot['counts']['9.6'])) exit(1);
fclose($snapshotFixture);
echo "Domain helper tests passed.\n";
