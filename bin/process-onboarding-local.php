#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
$configFile = getenv('MOONCRATER_ENV_FILE') ?: '/etc/mooncrater/apache-env.conf';
if (getenv('DB_USER') === false && is_readable($configFile)) {
    foreach (file($configFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^\s*SetEnv\s+(DB_HOST|DB_PORT|DB_NAME|DB_USER|DB_PASS)\s+(.+?)\s*$/', $line, $match)) {
            putenv($match[1] . '=' . trim($match[2], '"'));
        }
    }
}
define('APP_MIGRATING', true);
require __DIR__ . '/../app/config.php';

$mode = ($argv[1] ?? '') === '--registration' ? 'registration' : 'local_test';
if ($mode === 'registration') {
    require_once APP_ROOT . '/app/Domain/inventory.php';
    require_once APP_ROOT . '/onboarding/hammer.php';
    require_once APP_ROOT . '/onboarding/registration.php';
    if (!onboardingRegistrationEnabled() || !is_readable(ONBOARDING_REGISTRATION_PROFILE)) exit(1);
}
if (!is_executable('/usr/bin/ansible-playbook')) {
    fwrite(STDERR, "Install ansible-core to process local onboarding tests.\n");
    exit(1);
}

$claim = $pdo->prepare("
    WITH picked AS (
        SELECT id, ssh_credentials FROM onboarding_simulations
        WHERE mode = ? AND started_at IS NULL AND completed_at IS NULL
        ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED
    )
    UPDATE onboarding_simulations AS job
    SET started_at = CURRENT_TIMESTAMP, ssh_credentials = NULL
    FROM picked WHERE job.id = picked.id
    RETURNING job.id, job.targets,
        CASE WHEN job.created_at >= CURRENT_TIMESTAMP - interval '15 minutes'
            THEN picked.ssh_credentials END AS ssh_credentials
");
$claim->execute([$mode]);
$job = $claim->fetch(PDO::FETCH_ASSOC);
if ($job === false) exit(0);
$jobId = $job['id'];

$outcomes = [];
$save = $pdo->prepare('UPDATE onboarding_simulations SET outcomes = CAST(? AS jsonb) WHERE id = ?');
if ($mode === 'registration') {
    $targets = json_decode($job['targets'], true, flags: JSON_THROW_ON_ERROR);
    try {
        $credentials = onboardingOpenSshCredentials($job['ssh_credentials'] ?? '');
    } catch (RuntimeException | JsonException $e) {
        $credentials = null;
    }
    unset($job['ssh_credentials']);
    $protected = $pdo->prepare("SELECT 1 FROM inventory_satellite WHERE lower(hostname) = ? AND role IN ('satellite', 'capsule')");
    foreach ($targets as $target) {
        $fqdn = $target['fqdn'] ?? '';
        $outcome = ['state' => 'Precheck rejected'];
        try {
            if ($credentials === null) {
                $outcome['state'] = 'Target SSH credentials unavailable';
                throw new RuntimeException('Target SSH credentials unavailable.');
            }
            if (!onboardingRegistrationEnabled()) throw new RuntimeException('Registration disabled.');
            if (!is_string($fqdn) || !str_contains($fqdn, '.') || !is_valid_hostname($fqdn)
                || filter_var($fqdn, FILTER_VALIDATE_IP)) {
                throw new RuntimeException('Invalid target identity.');
            }
            $protected->execute([$fqdn]);
            if ($protected->fetchColumn() !== false) throw new RuntimeException('Infrastructure host is protected.');
            $find = static function () use ($fqdn): array {
                $inventory = onboardingHammerInventory();
                if ($inventory === null) throw new RuntimeException('Live Hammer is required.');
                return array_values(array_filter($inventory, static fn (array $row): bool => $row['name'] === $fqdn));
            };
            $existing = $find();
            $expectedExisting = $target['status'] === 'registered' && ($target['matches'] ?? []) === [$fqdn];
            if (count($existing) !== ($expectedExisting ? 1 : 0)) throw new RuntimeException('Satellite inventory changed.');
            $outcome['state'] = 'Ansible failed';
            $varsFile = tempnam('/dev/shm', 'mooncrater-vars-');
            if ($varsFile === false || dirname($varsFile) !== '/dev/shm') {
                if (is_string($varsFile)) unlink($varsFile);
                throw new RuntimeException('Unable to allocate a temporary credential file in memory.');
            }
            try {
                $payload = json_encode($credentials, JSON_THROW_ON_ERROR);
                if (!chmod($varsFile, 0600) || file_put_contents($varsFile, $payload) !== strlen($payload)) {
                    throw new RuntimeException('Unable to write temporary Ansible variables.');
                }
                unset($payload);
                $process = proc_open(
                ['/usr/bin/timeout', '--kill-after=10s', '300s', '/usr/bin/ansible-playbook',
                    '-i', $fqdn . ',', APP_ROOT . '/onboarding/register.yml',
                    '-e', '@' . ONBOARDING_REGISTRATION_PROFILE,
                    '-e', 'mooncrater_force=' . ($expectedExisting ? 'true' : 'false'), '-e', '@' . $varsFile],
                [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
                $pipes,
                '/tmp',
                ['PATH' => '/usr/bin:/bin', 'HOME' => '/var/lib/mooncrater-onboarding',
                    'NO_PROXY' => getenv('NO_PROXY') ?: '', 'no_proxy' => getenv('no_proxy') ?: '',
                    'ANSIBLE_CONFIG' => '/etc/mooncrater/onboarding-ansible.cfg', 'ANSIBLE_HOST_KEY_CHECKING' => 'True']
                );
                $outcome['exit_code'] = is_resource($process) ? proc_close($process) : 127;
            } finally {
                unlink($varsFile);
            }
            if ($outcome['exit_code'] !== 0 && $expectedExisting) {
                throw new RuntimeException('Ansible failed for an existing host.');
            }
            $outcome['state'] = 'Satellite verification failed';
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $matches = $find();
                if ($matches || $attempt === 2) break;
                sleep(3);
            }
            if (count($matches) !== 1) throw new RuntimeException('Registered host not confirmed.');
            $outcome['state'] = 'Inventory sync failed';
            onboardingSyncRegisteredHost($pdo, $matches[0]);
            $outcome['state'] = $outcome['exit_code'] === 0 ? 'Registered and inventory synced' : 'Host found and synced; Ansible reported an error';
        } catch (RuntimeException | JsonException $e) {
            // Save only the failed stage. Ansible output can contain registration credentials.
        }
        $outcomes[] = $outcome;
        $save->execute([json_encode($outcomes, JSON_THROW_ON_ERROR), $jobId]);
    }
    $done = $pdo->prepare('UPDATE onboarding_simulations SET completed_at = CURRENT_TIMESTAMP WHERE id = ?');
    $done->execute([$jobId]);
    exit;
}
foreach (['success', 'failure'] as $expected) {
    $process = proc_open(
        ['/usr/bin/timeout', '30s', '/usr/bin/ansible-playbook', '-i', 'localhost,',
            APP_ROOT . '/onboarding/local-test.yml', '-e', 'local_test_outcome=' . $expected],
        [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
        $pipes,
        '/tmp',
        ['PATH' => '/usr/bin:/bin', 'ANSIBLE_HOME' => sys_get_temp_dir() . '/mooncrater-ansible-' . getmypid(),
            'ANSIBLE_CONFIG' => APP_ROOT . '/onboarding/local-test.cfg', 'ANSIBLE_NOCOLOR' => '1']
    );
    $code = is_resource($process) ? proc_close($process) : 127;
    $outcomes[] = ['expected' => $expected, 'exit_code' => $code];
    $save->execute([json_encode($outcomes, JSON_THROW_ON_ERROR), $jobId]);
}
$finish = $pdo->prepare('UPDATE onboarding_simulations SET completed_at = CURRENT_TIMESTAMP WHERE id = ?');
$finish->execute([$jobId]);
