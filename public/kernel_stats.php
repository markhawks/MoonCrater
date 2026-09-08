<?php
require __DIR__ . '/../app/bootstrap.php';
$current_user = require_login($pdo);

date_default_timezone_set('Europe/Rome');

// Helper: estrae la major version RHEL dalla stringa OS
function detect_os_major($os_string) {
    $os = strtolower($os_string ?? '');
    if (preg_match('/(linux|server|rhel|redhat)\s+10/i', $os)) return '10';
    if (preg_match('/(linux|server|rhel|redhat)\s+9/i',  $os)) return '9';
    if (preg_match('/(linux|server|rhel|redhat)\s+8/i',  $os)) return '8';
    if (preg_match('/(linux|server|rhel|redhat)\s+7/i',  $os)) return '7';
    return 'unknown';
}

// Estrazione dati con ordinamento versionato
try {
    $kernel_stats_query = $pdo->query("
        SELECT os, kernel, COUNT(*) as total
        FROM inventory_satellite
        WHERE status = 'active'
          AND kernel IS NOT NULL
          AND kernel <> 'N/A'
          AND role = 'host'
        GROUP BY os, kernel
        ORDER BY
            (string_to_array(regexp_replace(os,     '[^0-9.]', '', 'g'), '.')::int[])[1] DESC,
            string_to_array(regexp_replace(os,     '[^0-9.]', '', 'g'), '.')::int[] DESC,
            string_to_array(regexp_replace(kernel, '[^0-9.]', '', 'g'), '.')::int[] DESC
    ");
    $kernel_stats = $kernel_stats_query->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $kernel_stats = [];
}

// KPI
$total_systems    = 0;
$distinct_kernels = count($kernel_stats);
foreach ($kernel_stats as $stat) {
    $total_systems += (int)$stat['total'];
}

// Raggruppa per OS major per i contatori nella card distribuzione
$by_major = ['7' => 0, '8' => 0, '9' => 0, '10' => 0, 'unknown' => 0];
foreach ($kernel_stats as $stat) {
    $m = detect_os_major($stat['os']);
    $by_major[$m] = ($by_major[$m] ?? 0) + (int)$stat['total'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kernel Statistics — MoonCreater</title>
    <link rel="icon" type="image/png" sizes="any" href="assets/img/favicon-mooncreater-130.png">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body {
            background-color: #1a252f;
            color: #e5e8e8;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 20px;
        }

        .container { max-width: 1400px; margin: 0 auto; }

        /* Header — identico alle altre pagine stats */
        .header-panel {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }

        .btn-back {
            background-color: rgba(255,255,255,0.1);
            color: #fff;
            padding: 8px 15px;
            border-radius: 4px;
            text-decoration: none;
            font-weight: bold;
            font-size: 0.9em;
            transition: background 0.2s;
        }
        .btn-back:hover { background-color: rgba(255,255,255,0.2); }

        /* KPI cards */
        .stats-row { display: flex; gap: 20px; margin-bottom: 25px; flex-wrap: nowrap; }

        .card {
            background-color: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.05);
            padding: 20px;
            border-radius: 6px;
            flex: 1;
        }

        .card strong {
            color: #ffffff;
            font-size: 1.05em;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: block;
        }

        /* Tabella */
        .table-container {
            width: 100%;
            overflow-x: auto;
            margin-top: 15px;
            background-color: rgba(0,0,0,0.1);
            border-radius: 4px;
        }

        table { width: 100%; border-collapse: collapse; table-layout: auto; }

        th {
            padding: 12px;
            text-align: left;
            color: #85929e;
            border-bottom: 2px solid rgba(255,255,255,0.05);
            font-weight: 600;
            font-size: 0.9em;
            white-space: nowrap;
        }

        td {
            padding: 12px;
            border-bottom: 1px solid rgba(255,255,255,0.02);
            font-size: 0.9em;
            vertical-align: middle;
        }

        tr:hover { background-color: rgba(255,255,255,0.02); }

        .nowrap-cell { white-space: nowrap !important; }

        /* Badge conteggio */
        .badge-count {
            background: rgba(52,152,219,0.2);
            color: #3498db;
            border: 1px solid rgba(52,152,219,0.3);
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.85em;
            font-weight: bold;
        }

        /* Badge OS colorati per major version */
        .os-badge { padding: 3px 8px; border-radius: 4px; font-size: 0.85em; font-weight: bold; color: #fff; white-space: nowrap; }
        .os-tag-7       { background-color: #e74c3c; }
        .os-tag-8       { background-color: #f39c12; }
        .os-tag-9       { background-color: #27ae60; }
        .os-tag-10      { background-color: #2980b9; }
        .os-tag-unknown { background-color: #7f8c8d; }

        /* Mini pill distribuzione OS nella card */
        .os-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 0.85em;
            font-weight: bold;
        }
    </style>
</head>
<body>

<div class="container">

    <!-- HEADER — allineato con statistics7/8/9/10/zabbix -->
    <div class="header-panel">
        <div>
            <h1 style="margin: 0; font-size: 1.8em; color: #fff;">⚙️ Kernel Version Distribution</h1>
            <p style="margin: 5px 0 0 0; color: #7f8c8d; font-size: 0.95em;">
                Kernel breakdown across all active RHEL systems managed by Red Hat Satellite
            </p>
        </div>
        <div>
            <a href="index.php" class="btn-back">⬅️ Back to Dashboard</a>
        </div>
    </div>

    <!-- KPI CARDS -->
    <div class="stats-row">

        <div class="card" style="border-left: 4px solid #3498db;">
            <strong>Total Managed Systems</strong>
            <span style="color: #3498db; font-size: 2.5em; font-weight: 800; display: block; margin-top: 5px;">
                <?= $total_systems ?>
            </span>
            <small style="color: #7f8c8d; display: block; margin-top: 5px;">
                Active hosts with a valid kernel tracked in Satellite
            </small>
        </div>

        <div class="card" style="border-left: 4px solid #2ecc71;">
            <strong>Distinct Kernel Builds</strong>
            <span style="color: #2ecc71; font-size: 2.5em; font-weight: 800; display: block; margin-top: 5px;">
                <?= $distinct_kernels ?>
            </span>
            <small style="color: #7f8c8d; display: block; margin-top: 5px;">
                Unique compiled kernel releases currently running
            </small>
        </div>

        <!-- Distribuzione per RHEL major -->
        <div class="card" style="border-left: 4px solid #9b59b6; flex: 2;">
            <strong style="margin-bottom: 12px;">OS Major Distribution</strong>
            <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px;">
                <?php if ($by_major['7'] > 0): ?>
                <span class="os-pill" style="background: rgba(231,76,60,0.15); color: #e74c3c; border: 1px solid rgba(231,76,60,0.3);">
                    🔴 RHEL 7 &nbsp;<strong><?= $by_major['7'] ?></strong>
                </span>
                <?php endif; ?>
                <?php if ($by_major['8'] > 0): ?>
                <span class="os-pill" style="background: rgba(243,156,18,0.15); color: #f39c12; border: 1px solid rgba(243,156,18,0.3);">
                    🟠 RHEL 8 &nbsp;<strong><?= $by_major['8'] ?></strong>
                </span>
                <?php endif; ?>
                <?php if ($by_major['9'] > 0): ?>
                <span class="os-pill" style="background: rgba(39,174,96,0.15); color: #2ecc71; border: 1px solid rgba(39,174,96,0.3);">
                    🟢 RHEL 9 &nbsp;<strong><?= $by_major['9'] ?></strong>
                </span>
                <?php endif; ?>
                <?php if ($by_major['10'] > 0): ?>
                <span class="os-pill" style="background: rgba(41,128,185,0.15); color: #85c1e9; border: 1px solid rgba(41,128,185,0.3);">
                    🔵 RHEL 10 &nbsp;<strong><?= $by_major['10'] ?></strong>
                </span>
                <?php endif; ?>
                <?php if ($by_major['unknown'] > 0): ?>
                <span class="os-pill" style="background: rgba(127,140,141,0.15); color: #95a5a6; border: 1px solid rgba(127,140,141,0.3);">
                    ⬜ Unknown &nbsp;<strong><?= $by_major['unknown'] ?></strong>
                </span>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- TABELLA KERNEL -->
    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h2 style="margin: 0; color: #fff; font-size: 1.1em;">
                📋 Kernel Version Breakdown
                <span style="font-size: 0.7em; font-weight: 400; color: #7f8c8d; margin-left: 10px;">
                    sorted by OS version then kernel version
                </span>
            </h2>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th style="width: 140px; text-align: center;">Systems</th>
                        <th>Operating System</th>
                        <th>Kernel Version</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($kernel_stats)): ?>
                        <?php foreach ($kernel_stats as $stat):
                            $os_major = detect_os_major($stat['os']);
                            $os_class = 'os-tag-' . $os_major;
                        ?>
                        <tr>
                            <td style="text-align: center;" class="nowrap-cell">
                                <span class="badge-count"><?= $stat['total'] ?> systems</span>
                            </td>
                            <td class="nowrap-cell">
                                <span class="os-badge <?= $os_class ?>">
                                    <?= htmlspecialchars($stat['os']) ?>
                                </span>
                            </td>
                            <td class="nowrap-cell" style="font-family: 'Courier New', monospace; font-weight: 600; color: #2ecc71;">
                                ⚙️ <?= htmlspecialchars($stat['kernel']) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="3" style="text-align: center; color: #7f8c8d; padding: 30px;">
                                ⚠️ No kernel data found in Satellite inventory.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>
</body>
</html>
