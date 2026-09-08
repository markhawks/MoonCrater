<?php
declare(strict_types=1);

const SATELLITE_CHECKIN_MAX_AGE_DAYS = 30;

function detect_os_major(?string $osString): string
{
    $os = strtolower($osString ?? '');
    foreach (['10', '9', '8', '7'] as $major) {
        if (preg_match('/(linux|server|rhel|redhat)\s+' . $major . '/i', $os)) return $major;
    }
    return 'unknown';
}

function normalize_hostname(?string $hostname): string
{
    $hostname = strtolower(trim($hostname ?? ''));
    if (filter_var($hostname, FILTER_VALIDATE_IP)) return $hostname;
    return explode('.', $hostname)[0];
}

function is_valid_hostname(string $hostname): bool
{
    if ($hostname === '' || strlen($hostname) > 253) return false;
    if (filter_var($hostname, FILTER_VALIDATE_IP)) return true;
    return (bool) preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/', strtolower($hostname));
}

function normalize_satellite_status(?string $status, bool $columnPresent): string
{
    if (!$columnPresent) return 'active';
    $status = strtolower(trim($status ?? ''));
    return in_array($status, ['active', 'enabled', 'true', 'yes', '1'], true)
        ? 'active'
        : 'missing';
}

function satellite_status_from_checkin(
    ?string $lastCheckin,
    ?DateTimeImmutable $now = null,
    int $maxAgeDays = SATELLITE_CHECKIN_MAX_AGE_DAYS
): string {
    $value = trim($lastCheckin ?? '');
    if ($value === '' || in_array(strtoupper($value), ['N/A', 'N/D', 'NULL'], true)) return 'unhealthy';

    try {
        $checkin = new DateTimeImmutable($value);
    } catch (Throwable) {
        return 'unhealthy';
    }

    $now ??= new DateTimeImmutable('now');
    if ($checkin > $now->modify('+1 hour')) return 'unhealthy';
    return $checkin >= $now->modify("-{$maxAgeDays} days") ? 'active' : 'unhealthy';
}
