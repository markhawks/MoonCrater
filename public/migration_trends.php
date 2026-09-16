<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
$currentUser = require_login($pdo);

$runs = $pdo->query("
    SELECT id, imported_at, source_filename, imported_count, skipped_count
    FROM satellite_import_runs
    ORDER BY imported_at ASC, id ASC
")->fetchAll();
$snapshots = $pdo->query("
    SELECT run_id, os_major, os_version, host_count
    FROM satellite_os_snapshots
    ORDER BY run_id, os_version
")->fetchAll();

$byRun = [];
$versions = [];
foreach ($snapshots as $snapshot) {
    $runId = (int)$snapshot['run_id'];
    $version = (string)$snapshot['os_version'];
    $byRun[$runId][$version] = (int)$snapshot['host_count'];
    $versions[$version] = true;
}
$versions = array_keys($versions);
usort($versions, 'version_compare');

$majors = ['7', '8', '9', '10'];
$versionsByMajor = array_fill_keys($majors, []);
foreach ($versions as $version) {
    $major = explode('.', $version, 2)[0];
    if (isset($versionsByMajor[$major])) $versionsByMajor[$major][] = $version;
}
$runPositions = [];
foreach ($runs as $position => $run) $runPositions[(int)$run['id']] = $position;
$majorTotals = [];
$maxTotal = 1;
foreach ($runs as $runIndex => $run) {
    foreach ($majors as $major) $majorTotals[$major][$runIndex] = 0;
    foreach ($byRun[(int)$run['id']] ?? [] as $version => $count) {
        $major = explode('.', $version, 2)[0];
        if (in_array($major, $majors, true)) {
            $majorTotals[$major][$runIndex] += $count;
            $maxTotal = max($maxTotal, $majorTotals[$major][$runIndex]);
        }
    }
}

$chartMinWidth = 1100;
$chartPointSpacing = 95;
$chartWidth = max($chartMinWidth, 135 + max(0, count($runs) - 1) * $chartPointSpacing);
$chartHeight = 370;
$padLeft = 55;
$padRight = 80;
$padTop = 28;
$padBottom = 72;
$plotWidth = $chartWidth - $padLeft - $padRight;
$plotHeight = $chartHeight - $padTop - $padBottom;
$colors = ['7' => '#e74c3c', '8' => '#f39c12', '9' => '#2ecc71', '10' => '#3498db'];
$runTimestamps = array_map(static fn($run) => (new DateTimeImmutable($run['imported_at']))->getTimestamp(), $runs);
$minTimestamp = $runTimestamps ? min($runTimestamps) : 0;
$maxTimestamp = $runTimestamps ? max($runTimestamps) : 0;
$timestampSpan = max(1, $maxTimestamp - $minTimestamp);
$runXPositions = [];
foreach ($runTimestamps as $timestamp) {
    $runXPositions[] = count($runs) > 1
        ? $padLeft + (($timestamp - $minTimestamp) * $plotWidth / $timestampSpan)
        : $padLeft + $plotWidth / 2;
}
function chart_points(array $values, array $xPositions, int $max, int $top, int $height): string
{
    $points = [];
    foreach ($values as $index => $value) {
        $x = $xPositions[$index];
        $y = $top + $height - ($value * $height / max(1, $max));
        $points[] = round($x, 1) . ',' . round($y, 1);
    }
    return implode(' ', $points);
}

$errorMessages = [
    'invalid_date' => 'Invalid import date.',
    'invalid_file' => 'The CSV file is invalid or too large.',
    'history_import' => 'Historical import failed. Check the server log.',
    'batch_explicit_date' => 'A manual date can only be used with a single CSV file.',
    'history_reset' => 'History reset failed. Check the server log.',
];
$error = $errorMessages[(string)($_GET['error'] ?? '')] ?? '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>RHEL Migration Trends</title>
    <link rel="icon" type="image/png" sizes="any" href="assets/img/favicon-mooncrater-130.png">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body{background:#1a252f;color:#e5e8e8;font-family:Segoe UI,Tahoma,sans-serif;margin:0;padding:20px}.container{width:100%;max-width:none;margin:auto;box-sizing:border-box}.header-panel{display:flex;justify-content:space-between;align-items:center;margin-bottom:25px;padding-bottom:15px;border-bottom:1px solid rgba(255,255,255,.05)}.btn-back{background-color:rgba(255,255,255,.1);color:#fff;padding:8px 15px;border-radius:4px;text-decoration:none;font-weight:bold;font-size:.9em;transition:background .2s}.btn-back:hover{background-color:rgba(255,255,255,.2)}.card{background:rgba(255,255,255,.035);border:1px solid rgba(255,255,255,.08);border-radius:8px;padding:18px;margin-bottom:18px}.button{color:#fff;text-decoration:none;background:#2471a3;border:0;border-radius:5px;padding:9px 14px;font-weight:700;cursor:pointer}.button.danger{background:#922b21}.form-row{display:flex;gap:12px;align-items:end;flex-wrap:wrap}.form-row label{display:flex;flex-direction:column;gap:6px;color:#abb2b9;font-size:13px}.form-row input{background:#17202a;color:#fff;border:1px solid #566573;border-radius:4px;padding:8px}.notice{padding:10px 12px;border-radius:5px;margin-bottom:14px}.ok{background:#145a32}.error{background:#922b21}.admin-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.legend{display:flex;gap:18px;flex-wrap:wrap;margin:10px 0}.dot{display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:5px}.chart{width:100%;overflow-x:auto;padding-bottom:7px}.chart svg{display:block;height:auto;max-width:none}.table-wrap{overflow:auto;max-height:65vh}table{border-collapse:collapse;width:100%;font-size:13px}th,td{padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.08);white-space:nowrap;text-align:right}th{position:sticky;top:0;background:#243442;color:#abb2b9;z-index:1}th:first-child,td:first-child,th:nth-child(2),td:nth-child(2){text-align:left}.delta{font-size:11px;color:#95a5a6}.up{color:#5dade2}.down{color:#f5b041}.empty{color:#7f8c8d}.muted{color:#95a5a6;font-size:13px}.summary{display:flex;gap:18px;flex-wrap:wrap}.summary span{background:rgba(0,0,0,.18);padding:6px 9px;border-radius:4px}.major-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.major-card{background:rgba(0,0,0,.14);border:1px solid rgba(255,255,255,.08);border-top:3px solid var(--major-color);border-radius:7px;padding:14px;min-width:0}.major-card h3{margin:0 0 10px;color:var(--major-color)}.major-card .table-wrap{max-height:48vh}.major-card table{font-size:12px}.major-card th,.major-card td{padding:8px 9px}.major-card th:first-child,.major-card td:first-child{text-align:left}@media(max-width:1050px){.major-grid,.admin-grid{grid-template-columns:1fr}}
    </style>
</head>
<body><main class="container">
    <div class="header-panel"><div><h1 style="margin:0;font-size:1.8em;color:#fff">RHEL Migration Trends</h1><p style="margin:5px 0 0;color:#7f8c8d;font-size:.95em">Satellite inventory changes over time</p></div><div><a class="btn-back" href="index.php">⬅️ Back to Dashboard</a></div></div>

    <?php if (isset($_GET['history_success'])): ?><div class="notice ok"><?= (int) $_GET['history_success'] ?> snapshots imported; <?= (int) ($_GET['history_replaced'] ?? 0) ?> replaced; <?= (int) ($_GET['history_failed'] ?? 0) ?> failed; <?= (int) ($_GET['history_ignored'] ?? 0) ?> non-Satellite files ignored.</div><?php endif; ?>
    <?php if (isset($_GET['history_reset'])): ?><div class="notice ok">Chart reset: <?= (int) $_GET['history_reset'] ?> snapshots deleted. The operational inventory was not changed.</div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="notice error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <?php if (($currentUser['role'] ?? '') === 'admin'): ?>
    <div class="admin-grid">
    <section class="card">
        <h2>Import Snapshot</h2>
        <p class="muted">The manual date is optional. When omitted, it is derived from the filename, for example <code>export-03092026.csv</code>.</p>
        <form class="form-row" method="post" action="import_satellite_history.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
            <label>Manual date (optional)<input type="date" name="import_date" max="<?= date('Y-m-d') ?>"></label>
            <label>CSV Satellite<input type="file" name="satellite_csvs[]" accept=".csv,text/csv" required></label>
            <button class="button" type="submit">Import into History</button>
        </form>
    </section>
    <section class="card">
        <h2>Reimport a Folder</h2>
        <p class="muted">Select a local folder. All CSV files are uploaded and each date is read from its filename.</p>
        <form class="form-row" method="post" action="import_satellite_history.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
            <label>CSV folder<input type="file" name="satellite_csvs[]" accept=".csv,text/csv" webkitdirectory directory multiple required></label>
            <button class="button" type="submit">Import Folder</button>
        </form>
        <p class="muted">For a folder already on the server: <code>php bin/import-satellite-history-dir.php /path/to/folder</code></p>
    </section>
    </div>
    <section class="card">
        <h2>Reset Chart</h2>
        <p class="muted">Deletes all snapshots and chart points. The Satellite inventory, host states, and notes are not changed.</p>
        <form method="post" action="reset_satellite_history.php" onsubmit="return confirm('Permanently delete all chart snapshots?');">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
            <button class="button danger" type="submit">Reset Chart</button>
        </form>
    </section>
    <?php endif; ?>

    <section class="card">
        <h2>Totals by Major Release</h2>
        <?php if (!$runs): ?><p class="empty">No snapshots available.</p><?php else: ?>
        <div class="legend"><?php foreach ($majors as $major): ?><span><i class="dot" style="background:<?= $colors[$major] ?>"></i>RHEL <?= $major ?></span><?php endforeach; ?></div>
        <div class="chart"><svg viewBox="0 0 <?= $chartWidth ?> <?= $chartHeight ?>" style="width:max(100%,<?= $chartWidth ?>px)" role="img" aria-label="Host trends by RHEL major release">
            <?php for ($grid = 0; $grid <= 4; $grid++): $gy = $padTop + $plotHeight * $grid / 4; $label = (int)round($maxTotal * (4 - $grid) / 4); ?>
                <line x1="<?= $padLeft ?>" y1="<?= $gy ?>" x2="<?= $padLeft + $plotWidth ?>" y2="<?= $gy ?>" stroke="rgba(255,255,255,.1)"/><text x="<?= $padLeft - 8 ?>" y="<?= $gy + 4 ?>" fill="#95a5a6" text-anchor="end" font-size="11"><?= $label ?></text>
            <?php endfor; ?>
            <?php foreach ($runs as $runIndex => $run): $axisX = $runXPositions[$runIndex]; $axisY = $padTop + $plotHeight; ?>
                <line x1="<?= $axisX ?>" y1="<?= $axisY ?>" x2="<?= $axisX ?>" y2="<?= $axisY + 6 ?>" stroke="#7f8c8d"/>
                <text x="<?= $axisX ?>" y="<?= $axisY + 23 ?>" fill="#abb2b9" font-size="11" text-anchor="end" transform="rotate(-35 <?= $axisX ?> <?= $axisY + 23 ?>)"><?= (new DateTimeImmutable($run['imported_at']))->format('d/m/Y H:i') ?></text>
            <?php endforeach; ?>
            <text x="<?= $padLeft + $plotWidth / 2 ?>" y="<?= $chartHeight - 5 ?>" fill="#7f8c8d" font-size="11" text-anchor="middle">Snapshot date</text>
            <?php foreach ($majors as $major): $points = chart_points($majorTotals[$major] ?? [], $runXPositions, $maxTotal, $padTop, $plotHeight); ?>
                <?php if (count($runs) > 1): ?><polyline points="<?= $points ?>" fill="none" stroke="<?= $colors[$major] ?>" stroke-width="3"/><?php endif; ?>
                <?php foreach ($majorTotals[$major] ?? [] as $i => $value): $xy = explode(' ', $points)[$i]; [$cx,$cy] = explode(',', $xy); $labelY = (float)$cy + (in_array($major, ['8', '10'], true) ? 17 : -9); $isLastPoint = $i === count($runs) - 1; $labelX = (float)$cx + ($isLastPoint ? -8 : 7); $labelAnchor = $isLastPoint ? 'end' : 'start'; ?><circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="5" fill="<?= $colors[$major] ?>" stroke="#1a252f" stroke-width="2"><title>RHEL <?= $major ?>: <?= $value ?></title></circle><text x="<?= $labelX ?>" y="<?= $labelY ?>" text-anchor="<?= $labelAnchor ?>" fill="<?= $colors[$major] ?>" font-size="12" font-weight="700" paint-order="stroke" stroke="#1a252f" stroke-width="4" stroke-linejoin="round"><?= $value ?></text><?php endforeach; ?>
            <?php endforeach; ?>
        </svg></div>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Differences by Version</h2>
        <p class="muted">Each cell shows the total and the change from the previous chronological snapshot.</p>
        <div class="major-grid">
        <?php foreach ($majors as $major): $majorVersions = $versionsByMajor[$major]; ?>
            <article class="major-card" style="--major-color:<?= $colors[$major] ?>">
                <h3>RHEL <?= $major ?></h3>
                <div class="table-wrap"><table>
                    <thead><tr><th>Snapshot date</th><?php foreach ($majorVersions as $version): ?><th><?= htmlspecialchars($version) ?></th><?php endforeach; ?></tr></thead>
                    <tbody>
                    <?php foreach (array_reverse($runs) as $run):
                        $runId = (int)$run['id'];
                        $runCounts = $byRun[$runId] ?? [];
                        $position = $runPositions[$runId];
                        $priorRun = $position > 0 ? $runs[$position - 1] : null;
                    ?><tr title="<?= htmlspecialchars($run['source_filename'], ENT_QUOTES, 'UTF-8') ?>">
                        <td><?= (new DateTimeImmutable($run['imported_at']))->format('d/m/Y H:i') ?></td>
                        <?php foreach ($majorVersions as $version):
                            $value = $runCounts[$version] ?? 0;
                            $prior = $priorRun ? ($byRun[(int)$priorRun['id']][$version] ?? 0) : null;
                            $delta = $prior === null ? null : $value - $prior;
                        ?><td><?= $value ?> <span class="delta <?= $delta > 0 ? 'up' : ($delta < 0 ? 'down' : '') ?>">(<?= $delta === null ? '-' : ($delta > 0 ? '+' : '') . $delta ?>)</span></td><?php endforeach; ?>
                        <?php if (!$majorVersions): ?><td class="empty">No versions detected</td><?php endif; ?>
                    </tr><?php endforeach; ?>
                    <?php if (!$runs): ?><tr><td class="empty">No data available.</td></tr><?php endif; ?>
                    </tbody>
                </table></div>
            </article>
        <?php endforeach; ?>
        </div>
    </section>
</main></body></html>
