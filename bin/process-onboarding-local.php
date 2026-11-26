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

if (!is_executable('/usr/bin/ansible-playbook')) {
    fwrite(STDERR, "Install ansible-core to process local onboarding tests.\n");
    exit(1);
}

$jobId = $pdo->query("
    UPDATE onboarding_simulations SET started_at = CURRENT_TIMESTAMP
    WHERE id = (
        SELECT id FROM onboarding_simulations
        WHERE mode = 'local_test' AND started_at IS NULL AND completed_at IS NULL
        ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED
    )
    RETURNING id
")->fetchColumn();
if ($jobId === false) exit(0);

$outcomes = [];
$save = $pdo->prepare('UPDATE onboarding_simulations SET outcomes = CAST(? AS jsonb) WHERE id = ?');
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
