<?php
declare(strict_types=1);

/** Return null only when the live profile has not been provisioned. */
function onboardingHammerInventory(): ?array
{
    $path = '/etc/mooncrater/onboarding-hammer.json';
    if (!file_exists($path)) return null;
    if (!is_readable($path)) throw new RuntimeException('Hammer profile unreadable.');
    if (!function_exists('proc_open')) throw new RuntimeException('Hammer SSH process support unavailable.');
    $profile = json_decode((string) file_get_contents($path), true);
    $host = $profile['host'] ?? '';
    $user = $profile['user'] ?? '';
    if (!is_string($host) || !preg_match('/\A[a-z0-9][a-z0-9.-]*\z/i', $host)
        || !is_string($user) || !preg_match('/\A[a-z_][a-z0-9_-]*\z/i', $user)) {
        throw new RuntimeException('Invalid Hammer SSH profile.');
    }
    $process = proc_open([
        '/usr/bin/timeout', '20s', '/usr/bin/ssh', '-F', '/dev/null',
        '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes',
        '-o', 'UserKnownHostsFile=/etc/mooncrater/onboarding_known_hosts',
        '-o', 'IdentitiesOnly=yes', '-i', PHP_SAPI === 'cli'
            ? (getenv('MOONCRATER_HAMMER_KEY') ?: '/etc/mooncrater/onboarding_ssh_key')
            : '/etc/mooncrater/onboarding_ssh_key',
        $user . '@' . $host, 'mooncrater-host-list',
    ], [['file', '/dev/null', 'r'], ['pipe', 'w'], ['file', '/dev/null', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Unable to start Hammer SSH lookup.');
    $output = stream_get_contents($pipes[1], 8 * 1024 * 1024 + 1);
    fclose($pipes[1]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0 || !is_string($output) || strlen($output) > 8 * 1024 * 1024) {
        throw new RuntimeException('Hammer SSH lookup failed or exceeded its output limit.');
    }
    return parseOnboardingHammerHosts($output);
}

function parseOnboardingHammerHosts(string $output): array
{
    $rows = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($rows) || !array_is_list($rows)) throw new RuntimeException('Unexpected Hammer host list.');
    $records = [];
    foreach ($rows as $row) {
        $name = $row['Name'] ?? null;
        if (!is_string($name) || trim($name) === '') throw new RuntimeException('Missing Hammer hostname.');
        $checkin = $row['Last Checkin'] ?? $row['Last Check-in'] ?? '';
        $os = $row['Operating System'] ?? null;
        $records[] = [
            'name' => rtrim(strtolower(trim($name)), '.'),
            'status' => 'active',
            'last_checkin' => is_string($checkin) ? $checkin : '',
            'os' => is_array($os) ? ($os['Name'] ?? null) : $os,
            'ip' => $row['IP'] ?? null,
            'content_view_environment' => $row['Content View Environments'] ?? null,
            'location' => is_array($row['Location'] ?? null) ? ($row['Location']['Name'] ?? null) : ($row['Location'] ?? null),
        ];
    }
    return $records;
}
