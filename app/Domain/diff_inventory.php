<?php
declare(strict_types=1);

function diff_inventory_header_key(string $value): string
{
    $value = strtolower(ltrim(trim($value), "\xEF\xBB\xBF"));
    return trim((string) preg_replace('/[^a-z0-9]+/', '_', $value), '_');
}

function diff_inventory_find_column(array $columns, array $aliases): ?int
{
    foreach ($aliases as $alias) {
        if (array_key_exists($alias, $columns)) return (int) $columns[$alias];
    }
    return null;
}

function diff_inventory_os(string $raw): array
{
    $os = trim($raw);
    if ($os === '' || in_array(strtoupper($os), ['N/A', 'N/D', 'UNKNOWN'], true)) {
        return ['family' => 'Unknown', 'version' => 'Unknown'];
    }
    if (preg_match('/\bdismess[aoe]?\b/i', $os)) return ['family' => 'Retired', 'version' => 'Retired'];

    $rules = [
        'Red Hat Enterprise Linux' => '/\b(?:Red Hat Enterprise Linux(?: Server)?|RHEL)\b.*?((?:10|[0-9])(?:\.[0-9]+)?)/i',
        'Oracle Linux' => '/\bOracle Linux(?: Server)?\b.*?([0-9]+(?:\.[0-9]+)?)/i',
        'SUSE Linux' => '/\bSUSE Linux Enterprise(?: Server)?\b.*?([0-9]+(?:\s*SP\s*[0-9]+)?)/i',
        'Ubuntu' => '/\bUbuntu\b.*?([0-9]+(?:\.[0-9]+){1,2})/i',
        'CentOS' => '/\bCentOS(?: Linux)?\b.*?([0-9]+(?:\.[0-9]+)?)/i',
    ];
    foreach ($rules as $family => $pattern) {
        if (preg_match($pattern, $os, $match)) {
            $version = preg_replace('/\s+/', ' ', strtoupper(trim($match[1])));
            return ['family' => $family, 'version' => $version];
        }
        $familyPattern = match ($family) {
            'Red Hat Enterprise Linux' => '/\b(?:Red Hat Enterprise Linux(?: Server)?|RHEL)\b/i',
            'Oracle Linux' => '/\bOracle Linux(?: Server)?\b/i',
            'SUSE Linux' => '/\bSUSE Linux/i',
            default => '/\b' . preg_quote($family, '/') . '\b/i',
        };
        if (preg_match($familyPattern, $os)) return ['family' => $family, 'version' => 'Unknown'];
    }
    return ['family' => 'Unknown', 'version' => 'Unknown'];
}

function diff_inventory_read_ivanti(string $file): array
{
    $handle = fopen($file, 'rb');
    if ($handle === false) throw new RuntimeException('Cannot open Ivanti CSV');
    try {
        $first = fgets($handle);
        if ($first === false) throw new RuntimeException('Empty Ivanti CSV');
        $delimiter = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
        rewind($handle);
        $header = fgetcsv($handle, 65536, $delimiter, '"', '\\');
        if (!is_array($header)) throw new RuntimeException('Missing Ivanti header');
        $columns = array_flip(array_map('diff_inventory_header_key', $header));
        $hostnameColumn = diff_inventory_find_column($columns, ['device_name', 'hostname', 'host_name']);
        $osColumn = diff_inventory_find_column($columns, ['os_name', 'os', 'operating_system', 'oslfabetico']);
        $scanColumn = diff_inventory_find_column($columns, ['last_hardware_scan_date', 'scan_date', 'last_scan_date']);
        if ($hostnameColumn === null) $hostnameColumn = 0;
        if ($osColumn === null && count($header) >= 2) $osColumn = 1;
        if ($osColumn === null) throw new RuntimeException('Ivanti OS column not found');

        $rows = [];
        $duplicates = 0;
        $invalid = 0;
        while (($data = fgetcsv($handle, 65536, $delimiter, '"', '\\')) !== false) {
            $hostname = strtolower(trim((string) ($data[$hostnameColumn] ?? '')));
            if (!is_valid_hostname($hostname)) {
                $invalid++;
                continue;
            }
            $key = normalize_hostname($hostname);
            if (isset($rows[$key])) {
                $duplicates++;
                continue;
            }
            $rows[$key] = [
                'hostname' => $hostname,
                'os' => trim((string) ($data[$osColumn] ?? '')) ?: 'N/D',
                'scan_date' => $scanColumn === null ? 'N/D' : (trim((string) ($data[$scanColumn] ?? '')) ?: 'N/D'),
            ];
        }
        return ['rows' => $rows, 'duplicates' => $duplicates, 'invalid' => $invalid, 'has_scan_date' => $scanColumn !== null];
    } finally {
        fclose($handle);
    }
}

function diff_inventory_read_satellite(string $file): array
{
    $handle = fopen($file, 'rb');
    if ($handle === false) throw new RuntimeException('Cannot open Satellite CSV');
    try {
        $first = fgets($handle);
        if ($first === false) throw new RuntimeException('Empty Satellite CSV');
        $delimiter = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
        rewind($handle);
        $header = fgetcsv($handle, 65536, $delimiter, '"', '\\');
        if (!is_array($header)) throw new RuntimeException('Missing Satellite header');
        $columns = array_flip(array_map('diff_inventory_header_key', $header));
        foreach (['hostname', 'os', 'content_view_environment', 'location', 'last_checkin'] as $required) {
            if (!array_key_exists($required, $columns)) throw new RuntimeException("Missing Satellite column: {$required}");
        }
        $rows = [];
        while (($data = fgetcsv($handle, 65536, $delimiter, '"', '\\')) !== false) {
            $hostname = strtolower(trim((string) ($data[$columns['hostname']] ?? '')));
            if (!is_valid_hostname($hostname)) continue;
            $rows[normalize_hostname($hostname)] = [
                'hostname' => $hostname,
                'os' => trim((string) ($data[$columns['os']] ?? '')) ?: 'N/D',
                'cv_env' => trim((string) ($data[$columns['content_view_environment']] ?? '')) ?: 'N/D',
                'location' => trim((string) ($data[$columns['location']] ?? '')) ?: 'N/D',
                'last_checkin' => trim((string) ($data[$columns['last_checkin']] ?? '')) ?: 'N/D',
            ];
        }
        return $rows;
    } finally {
        fclose($handle);
    }
}

function diff_inventory_date_from_filename(string $filename): ?DateTimeImmutable
{
    if (!preg_match_all('/(?<!\d)(\d{2})(\d{2})(\d{4})(?!\d)/', basename($filename), $matches, PREG_SET_ORDER)) {
        return null;
    }
    $match = end($matches);
    if (!checkdate((int) $match[2], (int) $match[1], (int) $match[3])) return null;
    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $match[3], $match[2], $match[1]));
}

function diff_inventory_checkin_is_stale(
    ?string $lastCheckin,
    DateTimeImmutable $snapshotDate,
    int $maxAgeDays = 30
): bool {
    $value = trim($lastCheckin ?? '');
    if ($value === '' || in_array(strtoupper($value), ['N/A', 'N/D', 'NULL'], true)) return false;
    try {
        $checkin = new DateTimeImmutable($value);
    } catch (Throwable) {
        return false;
    }
    $reference = $snapshotDate->setTime(23, 59, 59);
    return $checkin < $reference->modify("-{$maxAgeDays} days");
}

function diff_inventory_latest_csv(string $directory, string $requiredNamePart): ?string
{
    $files = glob(rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.csv') ?: [];
    $files = array_values(array_filter($files, static fn(string $file): bool => stripos(basename($file), $requiredNamePart) !== false));
    usort($files, static function (string $left, string $right): int {
        $timestamp = static function (string $file): int {
            $name = basename($file);
            if (preg_match_all('/(?<!\d)(\d{2})(\d{2})(\d{4})(?:[-_](\d{2})(\d{2}))?(?!\d)/', $name, $matches, PREG_SET_ORDER)) {
                $match = end($matches);
                $value = sprintf('%04d-%02d-%02d %02d:%02d:00', $match[3], $match[2], $match[1], $match[4] ?? 0, $match[5] ?? 0);
                try { return (new DateTimeImmutable($value))->getTimestamp(); } catch (Throwable) { return 0; }
            }
            return filemtime($file) ?: 0;
        };
        $timeOrder = $timestamp($right) <=> $timestamp($left);
        return $timeOrder !== 0 ? $timeOrder : strnatcasecmp(basename($right), basename($left));
    });
    return $files[0] ?? null;
}
