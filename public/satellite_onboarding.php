<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/onboarding/hammer.php';
require_once APP_ROOT . '/onboarding/registration.php';
require_once APP_ROOT . '/app/Domain/inventory.php';
require_admin($pdo);

function onboardingResolveFqdn(string $hostname): ?string
{
    if (!function_exists('proc_open') || !is_valid_hostname($hostname)) return null;
    $process = proc_open(
        ['/usr/bin/timeout', '1s', '/usr/bin/getent', 'hosts', $hostname],
        [['file', '/dev/null', 'r'], ['pipe', 'w'], ['file', '/dev/null', 'w']],
        $pipes
    );
    if (!is_resource($process)) return null;
    $output = stream_get_contents($pipes[1], 8192);
    fclose($pipes[1]);
    if (proc_close($process) !== 0 || !is_string($output)) return null;
    $names = [];
    foreach (preg_split('/\R/', trim($output)) as $line) {
        $fields = preg_split('/\s+/', trim($line));
        $name = rtrim(strtolower($fields[1] ?? ''), '.');
        if (str_contains($name, '.') && !filter_var($name, FILTER_VALIDATE_IP) && is_valid_hostname($name)) {
            $names[$name] = true;
        }
    }
    return count($names) === 1 ? array_key_first($names) : null;
}

function matchSatelliteInventory(PDO $pdo, array $hostnames): array
{
    if (!$hostnames) return [];

    $live = onboardingHammerInventory();
    if ($live === null) {
    $marks = implode(',', array_fill(0, count($hostnames), '?'));
    $statement = $pdo->prepare("
        WITH inventory AS (
            SELECT rtrim(lower(btrim(hostname)), '.') AS name, last_checkin, status
            FROM inventory_satellite
        )
        SELECT name, last_checkin, status FROM inventory
        WHERE name IN ($marks) OR split_part(name, '.', 1) IN ($marks)
    ");
    $statement->execute(array_merge($hostnames, $hostnames));
    }
    $byName = $byShortName = [];
    foreach ($live ?? $statement->fetchAll(PDO::FETCH_ASSOC) as $record) {
        $name = $record['name'];
        $short = explode('.', $name, 2)[0];
        $byName[$name][$name] = $record;
        $byShortName[$short][$name] = $record;
    }

    $result = [];
    $seen = [];
    $seenFqdns = [];
    $dnsDeadline = microtime(true) + 5;
    foreach ($hostnames as $hostname) {
        $matches = str_contains($hostname, '.') ? ($byName[$hostname] ?? []) : ($byShortName[$hostname] ?? []);
        $record = count($matches) === 1 ? reset($matches) : null;
        $status = 'not_found';
        if (count($matches) > 1) {
            $status = 'ambiguous';
        } elseif ($record !== null) {
            $status = isset($seen[$record['name']]) ? 'duplicate' :
                (in_array($record['status'], ['active', 'unhealthy'], true) ? 'registered' : 'historical');
            $seen[$record['name']] = true;
        }
        $fqdn = null;
        if (count($matches) <= 1) {
            $candidate = $record['name'] ?? $hostname;
            $fqdn = str_contains($candidate, '.') ? $candidate :
                (microtime(true) < $dnsDeadline ? onboardingResolveFqdn($candidate) : null);
        }
        if ($fqdn !== null) {
            if (isset($seenFqdns[$fqdn])) $status = 'duplicate';
            $seenFqdns[$fqdn] = true;
        }
        $result[] = [
            'hostname' => $hostname,
            'status' => $status,
            'matches' => array_keys($matches),
            'last_checkin' => $record['last_checkin'] ?? '',
            'source' => $live === null ? 'local' : 'hammer',
            'fqdn' => $fqdn,
        ];
    }
    return $result;
}

$preview = null;
$failure = '';
$inventoryInput = '';
$targetCredentialsAllowed = !empty($_SERVER['HTTPS']) && strcasecmp((string) $_SERVER['HTTPS'], 'off') !== 0;
$review = [];
$needsReregistration = false;
$previewToken = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && in_array($_POST['action'] ?? '', ['review', 'confirm'], true)) {
    require_csrf();
    $draft = $_SESSION['satellite_onboarding_preview'] ?? null;
    $token = $_POST['preview_token'] ?? null;
    $selected = $_POST['targets'] ?? [];
    if (!is_array($draft) || !is_string($token) || !hash_equals($draft['token'], $token) || $draft['expires'] < time()) {
        $failure = 'This preview has expired or been replaced. Validate the inventory again.';
    } elseif (!is_array($selected) || !$selected || count($selected) > count($draft['hosts'])) {
        $failure = 'Select at least one eligible host.';
    } else {
        $previewToken = $token;
        $inventoryInput = $draft['input'];
        $preview = $draft['preview'];
        $selectedHosts = [];
        foreach ($selected as $hostname) {
            if (!is_string($hostname) || !isset($draft['hosts'][$hostname]) || isset($selectedHosts[$hostname])) {
                $failure = 'The selection contains an invalid or duplicate target.';
                break;
            }
            $selectedHosts[$hostname] = $draft['hosts'][$hostname];
        }
        if ($failure === '') {
            try {
                $currentHosts = matchSatelliteInventory($pdo, array_keys($selectedHosts));
                foreach ($currentHosts as $host) {
                    $previous = $selectedHosts[$host['hostname']];
                    if ($host['source'] !== $previous['source'] || $host['status'] !== $previous['status']
                        || $host['matches'] !== $previous['matches'] || $host['fqdn'] !== ($previous['fqdn'] ?? null)) {
                        $failure = 'The inventory or lookup source changed. Validate the inventory again.';
                        $preview = null;
                        unset($_SESSION['satellite_onboarding_preview']);
                        break;
                    }
                    $needsReregistration = $needsReregistration || $host['status'] === 'registered';
                }
                if ($failure === '') $review = $currentHosts;
            } catch (RuntimeException | JsonException $e) {
                error_log('Satellite Onboarding review lookup failed: ' . $e->getMessage());
                $failure = 'Satellite precheck unavailable. Check the server log and validate again.';
            }
        }
        if ($review && ($_POST['action'] ?? '') === 'confirm') {
            $realRegistration = ($_POST['mode'] ?? '') === 'registration';
            $sealedCredentials = $realRegistration && $targetCredentialsAllowed ? onboardingSealSshCredentials(
                $_POST['target_ssh_user'] ?? null, $_POST['target_ssh_password'] ?? null
            ) : null;
            if ($needsReregistration && ($_POST['confirm_registered'] ?? '') !== 'yes') {
                $failure = 'Confirm that the selection includes already registered hosts.';
            } elseif ($realRegistration && (!onboardingRegistrationEnabled()
                || ($_POST['confirm_registration'] ?? '') !== 'yes'
                || count($review) > ONBOARDING_REGISTRATION_MAX_HOSTS
                || array_filter($review, static fn ($host) => $host['source'] !== 'hammer' || !$host['fqdn']))) {
                $failure = 'Real registration requires approval, a confirmed FQDN for each target, and live Hammer verification.';
            } elseif ($realRegistration && !$targetCredentialsAllowed) {
                $failure = 'Target SSH credentials require HTTPS.';
            } elseif ($realRegistration && $sealedCredentials === null) {
                $failure = 'Enter valid target SSH credentials and check the worker encryption configuration.';
            } else {
                try {
                    $statement = $pdo->prepare('INSERT INTO onboarding_simulations (created_by, targets, mode, ssh_credentials) VALUES (?, CAST(? AS jsonb), ?, ?) RETURNING id');
                    $statement->execute([(string) $_SESSION['username'], json_encode($review, JSON_THROW_ON_ERROR), $realRegistration ? 'registration' : 'simulation', $sealedCredentials]);
                    $jobId = (int) $statement->fetchColumn();
                    unset($_SESSION['satellite_onboarding_preview']);
                    header('Location: onboarding_simulations.php?job=' . $jobId, true, 303);
                    exit;
                } catch (PDOException $e) {
                    error_log('Onboarding simulation creation failed: ' . $e->getMessage());
                    $failure = 'Unable to create the simulation. Check that database migrations are current.';
                }
            }
        }
    }
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    unset($_SESSION['satellite_onboarding_preview']);
    $inventory = $_POST['inventory'] ?? null;
    if (is_string($inventory) && strlen($inventory) <= 65536 && preg_match('//u', $inventory)) {
        $safeLines = [];
        $skipSection = false;
        foreach (preg_split('/\R/u', $inventory) as $line) {
            $trimmed = trim(preg_replace('/^\xEF\xBB\xBF/', '', $line));
            if (str_starts_with($trimmed, '[')) {
                $skipSection = preg_match('/^\[[^\]]+:vars\s*\]/i', $trimmed) === 1;
            }
            if (!$skipSection && !str_contains($line, '=')) {
                $safeLines[] = $line;
            }
        }
        $inventoryInput = implode("\n", $safeLines);
    }
    if (!is_string($inventory) || strlen($inventory) > 65536 || !preg_match('//u', $inventory)) {
        $failure = 'Enter a UTF-8 inventory of up to 64 KB.';
    } elseif (!function_exists('proc_open')) {
        $failure = 'The inventory preview is unavailable. Check the server configuration.';
    } else {
        $process = proc_open(
            ['/usr/bin/python3', '-B', APP_ROOT . '/onboarding/preview.py'],
            [['pipe', 'r'], ['pipe', 'w'], ['file', '/dev/null', 'w']],
            $pipes
        );
        if (is_resource($process)) {
            $written = fwrite($pipes[0], $inventory);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1], 524288);
            fclose($pipes[1]);
            $exitCode = proc_close($process);
            if ($written === strlen($inventory) && $exitCode === 0 && is_string($output)) {
                $preview = json_decode($output, true);
            }
        }
        if (!is_array($preview)) {
            $failure = 'The inventory preview is unavailable. Check the server configuration.';
        } else {
            try {
                $preview['hosts'] = matchSatelliteInventory($pdo, $preview['hosts']);
                if (!$preview['errors']) {
                    $eligible = array_filter($preview['hosts'], static fn ($host) =>
                        !in_array($host['status'], ['ambiguous', 'duplicate'], true));
                    $previewToken = bin2hex(random_bytes(32));
                    $_SESSION['satellite_onboarding_preview'] = [
                        'token' => $previewToken,
                        'expires' => time() + 900,
                        'input' => $inventoryInput,
                        'preview' => $preview,
                        'hosts' => array_column($eligible, null, 'hostname'),
                    ];
                }
            } catch (RuntimeException | JsonException $e) {
                error_log('Satellite Onboarding inventory lookup failed: ' . $e->getMessage());
                $preview = null;
                $failure = 'Satellite precheck unavailable. Check the server log and validate again.';
            }
        }
    }
}
$statusLabels = [
    'registered' => 'Host found',
    'not_found' => 'Host not found in lookup source',
    'historical' => 'Historical record · registration unverified',
    'ambiguous' => 'Multiple matches · enter a FQDN',
    'duplicate' => 'Duplicate of another target',
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Satellite Onboarding</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .onboarding { max-width: 960px; margin: 24px auto; padding: 0 16px; }
        .onboarding header { display: flex; justify-content: space-between; align-items: baseline; gap: 16px; flex-wrap: wrap; }
        .onboarding a { color: #85c1e9; }
        .onboarding textarea { display: block; width: 100%; box-sizing: border-box; margin: 8px 0 12px; padding: 12px; background: #17202a; color: #fff; border: 1px solid #566573; }
        .onboarding th, .onboarding td { text-align: left; }
        .onboarding .error { color: #ffabab; }
    </style>
</head>
<body>
<main class="onboarding">
    <header><h1>Satellite Onboarding</h1><nav><a href="index.php">← Back to Dashboard</a> · <a href="onboarding_simulations.php">Simulation History</a></nav></header>
    <section class="card">
        <h2>Inventory Preview</h2>
        <p>The precheck uses Hammer when configured. Otherwise it checks the last imported Satellite inventory.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <label for="inventory">One hostname per line, an INI [targets] group, or site|asset|hostname</label>
            <textarea id="inventory" name="inventory" rows="8" maxlength="65536" required spellcheck="false" autocomplete="off"><?= htmlspecialchars($inventoryInput, ENT_QUOTES, 'UTF-8') ?></textarea>
            <button type="submit">Validate Inventory</button>
        </form>
    </section>
    <?php if ($failure !== ''): ?>
        <p class="error" role="alert"><?= htmlspecialchars($failure) ?></p>
    <?php endif; ?>
    <?php if ($review): ?>
        <section class="card">
            <h2>Review Selection</h2>
            <p><?= count($review) ?> selected host(s).</p>
            <ul>
                <?php foreach ($review as $host): ?>
                    <li><?= htmlspecialchars($host['fqdn'] ?? $host['matches'][0] ?? $host['hostname']) ?> · <?= htmlspecialchars($statusLabels[$host['status']]) ?> (<?= $host['source'] === 'hammer' ? 'Hammer' : 'local inventory' ?>)</li>
                <?php endforeach; ?>
            </ul>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <input type="hidden" name="action" value="confirm">
                    <input type="hidden" name="preview_token" value="<?= htmlspecialchars($previewToken) ?>">
                    <?php foreach ($review as $host): ?>
                        <input type="hidden" name="targets[]" value="<?= htmlspecialchars($host['hostname']) ?>">
                    <?php endforeach; ?>
                    <?php if ($needsReregistration): ?>
                        <p><label><input type="checkbox" name="confirm_registered" value="yes" required> I confirm that this selection includes hosts found in Satellite inventory.</label></p>
                    <?php endif; ?>
                    <?php if (onboardingRegistrationEnabled() && $targetCredentialsAllowed): ?>
                        <fieldset>
                            <legend>Target SSH credentials for this registration</legend>
                            <label>SSH user <input name="target_ssh_user" maxlength="64" autocomplete="off"></label>
                            <label>SSH password <input type="password" name="target_ssh_password" maxlength="128" autocomplete="new-password"></label>
                            <p>These credentials apply to all selected hosts.</p>
                        </fieldset>
                        <p><label><input type="checkbox" name="confirm_registration" value="yes"> I authorize registration and Host Collection assignment for these targets. Existing hosts in this selection will be re-registered.</label></p>
                        <button type="submit" name="mode" value="registration">Start Satellite Registration</button>
                    <?php elseif (onboardingRegistrationEnabled()): ?>
                        <p>Open the portal over HTTPS to enter target SSH credentials.</p>
                    <?php endif; ?>
                    <button type="submit" name="mode" value="simulation">Start Simulation</button>
                </form>
        </section>
    <?php endif; ?>
    <?php if (is_array($preview)): ?>
        <section class="card">
            <h2>Precheck Results</h2>
            <?php foreach (['errors', 'warnings'] as $severity): ?>
                <?php if ($preview[$severity]): ?>
                    <ul class="<?= $severity === 'errors' ? 'error' : '' ?>">
                        <?php foreach ($preview[$severity] as $issue): ?>
                            <li><?php if ($issue['line'] !== null): ?>Line <?= (int) $issue['line'] ?>: <?php endif; ?><?= htmlspecialchars($issue['message']) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($preview['errors']): ?>
                <p>Correct the errors and submit the inventory again to enable selection.</p>
            <?php endif; ?>
            <?php if ($preview['hosts']): ?>
                <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="review">
                <input type="hidden" name="preview_token" value="<?= htmlspecialchars($previewToken) ?>">
                <label><input type="checkbox" id="select-all-targets" disabled> Select all</label>
                <table class="table">
                    <thead><tr><th scope="col">Select</th><th scope="col">Hostname</th><th scope="col">FQDN</th><th scope="col">Inventory Match</th><th scope="col">Last Check-in</th><th scope="col">Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($preview['hosts'] as $host): ?>
                        <tr>
                            <td><input type="checkbox" name="targets[]" value="<?= htmlspecialchars($host['hostname']) ?>" class="onboarding-target" aria-label="<?= htmlspecialchars('Select ' . $host['hostname']) ?>"
                                <?= in_array($host['hostname'], array_column($review, 'hostname'), true) ? 'checked' : '' ?>
                                <?= $preview['errors'] || in_array($host['status'], ['ambiguous', 'duplicate'], true) ? 'disabled' : '' ?>></td>
                            <td><?= htmlspecialchars($host['hostname']) ?></td>
                            <td><?= htmlspecialchars($host['fqdn'] ?? '—') ?></td>
                            <td><?= htmlspecialchars(implode(', ', $host['matches']) ?: '—') ?></td>
                            <td><?= htmlspecialchars($host['last_checkin'] ?: '—') ?></td>
                            <td><?= htmlspecialchars($statusLabels[$host['status']]) ?> (<?= $host['source'] === 'hammer' ? 'Hammer' : 'local inventory' ?>)</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <button type="submit" <?= $preview['errors'] ? 'disabled' : '' ?>>Review Selected Hosts</button>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
<script>
    const selectAll = document.getElementById('select-all-targets');
    if (selectAll) {
        const targets = Array.from(document.querySelectorAll('.onboarding-target:not(:disabled)'));
        selectAll.disabled = targets.length === 0;
        selectAll.addEventListener('change', () => {
            targets.forEach(target => { target.checked = selectAll.checked; });
        });
        targets.forEach(target => target.addEventListener('change', () => {
            const checked = targets.filter(item => item.checked).length;
            selectAll.checked = checked === targets.length;
            selectAll.indeterminate = checked > 0 && checked < targets.length;
        }));
    }
</script>
</body>
</html>
