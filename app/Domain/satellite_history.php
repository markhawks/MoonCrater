<?php
declare(strict_types=1);

function satellite_snapshot_date_from_filename(string $filename): ?DateTimeImmutable
{
    $name = basename($filename);
    $candidates = [];
    if (preg_match_all('/(?<!\d)(\d{2})(\d{2})(\d{4})(?!\d)/', $name, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) $candidates[] = [$match[3], $match[2], $match[1]];
    }
    if (preg_match_all('/(?<!\d)(\d{4})[-_](\d{2})[-_](\d{2})(?!\d)/', $name, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) $candidates[] = [$match[1], $match[2], $match[3]];
    }
    if (preg_match_all('/(?<!\d)(\d{4})(\d{2})(\d{2})(?!\d)/', $name, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) $candidates[] = [$match[1], $match[2], $match[3]];
    }
    foreach (array_reverse($candidates) as [$year, $month, $day]) {
        if (checkdate((int) $month, (int) $day, (int) $year)) {
            return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
        }
    }
    return null;
}

function satellite_snapshot_resolve_date(string $explicitDate, string $filename): DateTimeImmutable
{
    if ($explicitDate !== '') {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $explicitDate);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || $date->format('Y-m-d') !== $explicitDate
            || (is_array($errors) && ($errors['warning_count'] || $errors['error_count']))) {
            throw new InvalidArgumentException('Invalid explicit snapshot date');
        }
        if ($date > new DateTimeImmutable('today')) throw new InvalidArgumentException('Snapshot date is in the future');
        if ($date > new DateTimeImmutable('today')) throw new InvalidArgumentException('Snapshot date is in the future');
        return $date;
    }
    $date = satellite_snapshot_date_from_filename($filename);
    if (!$date) throw new InvalidArgumentException('Snapshot date not found in filename');
    if ($date > new DateTimeImmutable('today')) throw new InvalidArgumentException('Snapshot date is in the future');
    if ($date > new DateTimeImmutable('today')) throw new InvalidArgumentException('Snapshot date is in the future');
    return $date;
}

function satellite_snapshot_read_csv(string $file, DateTimeImmutable $snapshotDate): array
{
    $handle = fopen($file, 'rb');
    if ($handle === false) throw new RuntimeException('Cannot open CSV');
    try {
        $firstLine = fgets($handle);
        if ($firstLine === false) throw new RuntimeException('Empty CSV');
        $separator = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
        rewind($handle);
        $header = fgetcsv($handle, 65536, $separator, '"', '\\');
        if (!is_array($header)) throw new RuntimeException('Missing CSV header');
        $header = array_map(static fn($name) => strtolower(trim((string) $name)), $header);
        if (isset($header[0])) $header[0] = ltrim($header[0], "\xEF\xBB\xBF");
        $columns = array_flip($header);
        foreach (['hostname', 'os', 'last_checkin'] as $required) {
            if (!array_key_exists($required, $columns)) throw new RuntimeException("Missing CSV column: {$required}");
        }
        $statusColumn = $columns['status'] ?? null;
        $reference = new DateTimeImmutable($snapshotDate->format('Y-m-d') . ' 23:59:59', new DateTimeZone(date_default_timezone_get()));
        $hosts = [];
        $skipped = 0;
        while (($row = fgetcsv($handle, 65536, $separator, '"', '\\')) !== false) {
            $hostname = strtolower(trim((string) ($row[$columns['hostname']] ?? '')));
            if (!is_valid_hostname($hostname)) {
                $skipped++;
                continue;
            }
            $rawStatus = $statusColumn !== null ? (string) ($row[$statusColumn] ?? '') : null;
            $sourceActive = normalize_satellite_status($rawStatus, $statusColumn !== null) === 'active';
            $lastCheckin = trim((string) ($row[$columns['last_checkin']] ?? ''));
            if (!$sourceActive || satellite_status_from_checkin($lastCheckin, $reference) !== 'active') {
                $hosts[$hostname] = null;
                continue;
            }
            $os = trim((string) ($row[$columns['os']] ?? ''));
            if (!preg_match('/\b(?:Red Hat Enterprise Linux|RedHat|RHEL(?: Server)?)\D*(10|[789])(?:\.(\d+))?/i', $os, $match)) {
                $hosts[$hostname] = null;
                continue;
            }
            $hosts[$hostname] = $match[1] . (!empty($match[2]) ? '.' . $match[2] : '');
        }
    } finally {
        fclose($handle);
    }
    $counts = [];
    foreach ($hosts as $version) {
        if ($version !== null) $counts[$version] = ($counts[$version] ?? 0) + 1;
    }
    return ['host_count' => count($hosts), 'skipped_count' => $skipped, 'counts' => $counts];
}

function satellite_snapshot_store(PDO $pdo, string $filename, DateTimeImmutable $date, array $snapshot): array
{
    $timezone = new DateTimeZone(date_default_timezone_get());
    $importedAt = new DateTimeImmutable($date->format('Y-m-d') . ' 12:00:00', $timezone);
    $pdo->beginTransaction();
    try {
        $delete = $pdo->prepare('DELETE FROM satellite_import_runs WHERE imported_at::date = ?');
        $delete->execute([$date->format('Y-m-d')]);
        $replaced = $delete->rowCount();
        $run = $pdo->prepare("
            INSERT INTO satellite_import_runs
                (imported_at, source_filename, imported_count, skipped_count, missing_count, has_status_column)
            VALUES (?, ?, ?, ?, 0, FALSE)
            RETURNING id
        ");
        $run->execute([
            $importedAt->format(DateTimeInterface::ATOM), basename($filename),
            $snapshot['host_count'], $snapshot['skipped_count'],
        ]);
        $runId = (int) $run->fetchColumn();
        $insert = $pdo->prepare('INSERT INTO satellite_os_snapshots (run_id, os_major, os_version, host_count) VALUES (?, ?, ?, ?)');
        foreach ($snapshot['counts'] as $version => $count) {
            $insert->execute([$runId, explode('.', $version, 2)[0], $version, $count]);
        }
        $pdo->commit();
        return ['run_id' => $runId, 'replaced' => $replaced];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
