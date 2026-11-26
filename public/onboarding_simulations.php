<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require_admin($pdo);

$jobId = filter_input(INPUT_GET, 'job', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$jobs = [];
$job = null;
$targets = [];
$completed = 0;
$error = '';
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        require_csrf();
        $statement = $pdo->prepare("INSERT INTO onboarding_simulations (created_by, targets, mode) VALUES (?, CAST(? AS jsonb), 'local_test') RETURNING id");
        $statement->execute([(string) $_SESSION['username'], json_encode([
            ['hostname' => 'localhost-success'],
            ['hostname' => 'localhost-controlled-failure'],
        ], JSON_THROW_ON_ERROR)]);
        header('Location: onboarding_simulations.php?job=' . (int) $statement->fetchColumn(), true, 303);
        exit;
    }
    $pdo->exec("
        UPDATE onboarding_simulations
        SET completed_at = created_at + jsonb_array_length(targets) * interval '2 seconds'
        WHERE mode = 'simulation' AND completed_at IS NULL
          AND CURRENT_TIMESTAMP >= created_at + jsonb_array_length(targets) * interval '2 seconds'
    ");
    $jobs = $pdo->query('SELECT id, created_by, created_at, started_at, completed_at, mode, jsonb_array_length(targets) AS total FROM onboarding_simulations ORDER BY id DESC LIMIT 30')->fetchAll(PDO::FETCH_ASSOC);
    if ($jobId !== null) {
        $statement = $pdo->prepare('SELECT *, EXTRACT(EPOCH FROM CURRENT_TIMESTAMP - created_at) AS elapsed FROM onboarding_simulations WHERE id = ?');
        $statement->execute([$jobId]);
        $job = $statement->fetch(PDO::FETCH_ASSOC);
        if ($job) {
            $targets = json_decode($job['targets'], true, flags: JSON_THROW_ON_ERROR);
            $outcomes = json_decode($job['outcomes'], true, flags: JSON_THROW_ON_ERROR);
            $completed = $job['mode'] === 'local_test' ? count($outcomes) : min(count($targets), max(0, (int) floor((float) $job['elapsed'] / 2)));
        } else {
            http_response_code(404);
            $error = 'Simulation not found.';
        }
    }
} catch (PDOException | JsonException $e) {
    error_log('Onboarding simulations unavailable: ' . $e->getMessage());
    http_response_code(500);
    $error = 'The simulation history is unavailable.';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if ($job && $completed < count($targets)): ?><meta http-equiv="refresh" content="2"><?php endif; ?>
    <title>Satellite Onboarding Simulations</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<main style="max-width:960px;margin:24px auto;padding:0 16px;">
    <h1>Satellite Onboarding Simulations</h1>
    <p><a href="satellite_onboarding.php" style="color:#85c1e9;">← Back to Satellite Onboarding</a></p>
    <form method="post" action="onboarding_simulations.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <button type="submit">Queue Local Ansible Test</button>
    </form>
    <p>The local test runs two fixed checks on localhost. One succeeds and one intentionally fails.</p>
    <?php if ($error !== ''): ?><p role="alert"><?= htmlspecialchars($error) ?></p><?php endif; ?>
    <?php if ($job && $targets): ?>
        <section class="card">
            <h2><?= $job['mode'] === 'local_test' ? 'Local Ansible Test' : 'Simulation' ?> #<?= (int) $job['id'] ?></h2>
            <label for="progress"><?= $completed ?> of <?= count($targets) ?> completed</label>
            <progress id="progress" value="<?= $completed ?>" max="<?= count($targets) ?>" style="width:100%;"></progress>
            <table class="table">
                <thead><tr><th scope="col">Host</th><th scope="col">Outcome</th></tr></thead>
                <tbody>
                <?php foreach ($targets as $index => $host): ?>
                    <tr>
                        <td><?= htmlspecialchars($host['matches'][0] ?? $host['hostname']) ?></td>
                        <td><?php if ($job['mode'] === 'local_test'): ?>
                            <?php if (isset($outcomes[$index])): ?>
                                <?= $outcomes[$index]['exit_code'] === 0 ? 'Passed' : 'Failed' ?> (exit <?= (int) $outcomes[$index]['exit_code'] ?>)
                            <?php else: ?>
                                <?= $job['started_at'] && $index === $completed ? 'In progress' : 'Queued' ?>
                            <?php endif; ?>
                        <?php else: ?><?= $index < $completed
                            ? ($host['status'] === 'registered' ? 'Re-registration simulated' : 'Registration simulated')
                            : ($index === $completed ? 'In progress' : 'Queued') ?><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>
    <section class="card">
        <h2>Recent Simulations</h2>
        <?php if ($jobs): ?>
            <table class="table">
                <thead><tr><th scope="col">Job</th><th scope="col">Started</th><th scope="col">Operator</th><th scope="col">Hosts</th><th scope="col">Status</th></tr></thead>
                <tbody>
                <?php foreach ($jobs as $entry): ?>
                    <tr>
                        <td><a style="color:#85c1e9;" href="?job=<?= (int) $entry['id'] ?>">#<?= (int) $entry['id'] ?></a> · <?= $entry['mode'] === 'local_test' ? 'Local Ansible Test' : 'Simulation' ?></td>
                        <td><?= htmlspecialchars($entry['created_at']) ?></td>
                        <td><?= htmlspecialchars($entry['created_by']) ?></td>
                        <td><?= (int) $entry['total'] ?></td>
                        <td><?= $entry['completed_at'] ? 'Completed' : ($entry['mode'] === 'local_test' && !$entry['started_at'] ? 'Queued' : 'In progress') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No simulations yet.</p>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
