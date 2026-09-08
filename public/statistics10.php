<?php
require __DIR__ . '/../app/bootstrap.php';
$current_user = require_login($pdo);

// 2. Data Extraction & Sorting (RHEL 10)
// RHEL 10 su Satellite si chiama "Red Hat Enterprise Linux 10.x (Coughlan)"
$satellite_hosts = [];
try {
    $stmt = $pdo->prepare("
        SELECT hostname, os, ip, kernel, content_view_environment, location, last_checkin
        FROM inventory_satellite
        WHERE (
            os ILIKE '%RedHat 10%'
            OR os ILIKE '%RHEL Server 10%'
            OR os ILIKE '%RHEL 10%'
            OR os ILIKE '%Red Hat%10%'
            OR os ILIKE '%Red Hat Enterprise Linux 10%'
          )
          AND role = 'host'
          AND status = 'active'
        ORDER BY
            CASE
                WHEN regexp_replace(os, '^[^0-9]*10\.([0-9]+).*$', '\1') ~ '^[0-9]+$'
                THEN CAST(regexp_replace(os, '^[^0-9]*10\.([0-9]+).*$', '\1') AS INTEGER)
                ELSE 999
            END ASC,
            kernel ASC
    ");
    $stmt->execute();
    $satellite_hosts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Statistics query failed: " . $e->getMessage()); http_response_code(500); exit("Unable to load statistics.");
}

// 3. KPI Calculations
$total_rhel10 = count($satellite_hosts);

$missing_ip_count = 0;
foreach ($satellite_hosts as $host) {
    $ip = trim($host['ip'] ?? '');
    if (empty($ip) || $ip === 'N/A' || $ip === 'N/D') {
        $missing_ip_count++;
    }
}

// Group by minor release
$minor_distribution = [];
foreach ($satellite_hosts as $host) {
    $os_name = $host['os'] ?? 'Unknown RHEL 10';
    $minor_distribution[$os_name] = ($minor_distribution[$os_name] ?? 0) + 1;
}
uksort($minor_distribution, 'strnatcasecmp');

// 4. Last check-in analysis (>3 days = stale)
$stale_checkin_count = 0;
$thirty_days_ago     = (new DateTime())->modify('-3 days');
foreach ($satellite_hosts as $host) {
    $lc = trim($host['last_checkin'] ?? '');
    if (empty($lc) || $lc === 'N/A' || $lc === 'N/D') {
        $stale_checkin_count++;
    } else {
        $lc_obj = DateTime::createFromFormat('Y-m-d', substr($lc, 0, 10));
        if ($lc_obj && $lc_obj < $thirty_days_ago) {
            $stale_checkin_count++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Satellite Statistics - RHEL 10</title>
    <link rel="icon" type="image/png" sizes="any" href="assets/img/favicon-mooncreater-130.png">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { background-color: #1a252f; color: #e5e8e8; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 0; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        .header-panel { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .stats-row { display: flex; gap: 20px; margin-bottom: 25px; flex-wrap: nowrap; }
        .card { background-color: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05); padding: 20px; border-radius: 6px; flex: 1; }
        .btn-back { background-color: rgba(255,255,255,0.1); color: #fff; padding: 8px 15px; border-radius: 4px; text-decoration: none; font-weight: bold; font-size: 0.9em; transition: background 0.2s; }
        .btn-back:hover { background-color: rgba(255,255,255,0.2); }

        .table-container { width: 100%; overflow-x: auto; margin-top: 15px; background-color: rgba(0,0,0,0.1); border-radius: 4px; }
        table { width: 100%; border-collapse: collapse; table-layout: auto; }
        th { padding: 12px; text-align: left; color: #85929e; border-bottom: 2px solid rgba(255,255,255,0.05); font-weight: 600; font-size: 0.9em; white-space: nowrap; }
        td { padding: 12px; border-bottom: 1px solid rgba(255,255,255,0.02); font-size: 0.9em; vertical-align: middle; }
        tr:hover { background-color: rgba(255,255,255,0.02); }
        .nowrap-cell { white-space: nowrap !important; }

        /* RHEL 10 — colore identificativo blu scuro */
        .minor-group-header {
            background-color: rgba(44, 62, 80, 0.4);
            color: #85c1e9;
            font-weight: bold;
            font-size: 1em;
            padding: 10px 15px;
            margin-top: 30px;
            border-left: 4px solid #2c3e50;
            border-radius: 0 4px 4px 0;
            display: flex;
            justify-content: space-between;
        }
        .badge-count { background: #2c3e50; color: #85c1e9; border: 1px solid #85c1e9; padding: 2px 8px; border-radius: 10px; font-size: 0.8em; }

        .stale-row td { opacity: 0.7; }
        .stale-badge { color: #e74c3c; font-size: 0.85em; }
    </style>
</head>
<body>

<div class="container">

    <!-- HEADER -->
    <div class="header-panel">
        <div>
            <h1 style="margin: 0; font-size: 1.8em; color: #fff;">🔵 Satellite Analytics - RHEL 10 Infrastructure</h1>
            <p style="margin: 5px 0 0 0; color: #7f8c8d; font-size: 0.95em;">Dedicated statistics for Red Hat Enterprise Linux 10 environments</p>
        </div>
        <div>
            <a href="index.php" class="btn-back">⬅️ Back to Dashboard</a>
        </div>
    </div>

    <!-- KPI CARDS -->
    <div class="stats-row">

        <div class="card" style="border-left: 4px solid #2c3e50;">
            <h3 style="margin: 0; color: #85929e; font-size: 1em; text-transform: uppercase;">Total RHEL 10 Hosts</h3>
            <div style="font-size: 2.2em; font-weight: 800; color: #85c1e9; margin-top: 10px;"><?= $total_rhel10 ?></div>
            <p style="margin: 5px 0 0 0; color: #7f8c8d; font-size: 0.8em;">Next-generation RHEL 10 (Coughlan) environments managed by Satellite.</p>
        </div>

        <div class="card" style="border-left: 4px solid #e74c3c;">
            <h3 style="margin: 0; color: #85929e; font-size: 1em; text-transform: uppercase;">⚠️ Agent Health Anomalies</h3>
            <div style="font-size: 2.2em; font-weight: 800; color: #e74c3c; margin-top: 10px;"><?= $missing_ip_count ?></div>
            <p style="margin: 5px 0 0 0; color: #7f8c8d; font-size: 0.8em;">Active systems missing a registered IP address.</p>
        </div>

        <div class="card" style="border-left: 4px solid #f39c12;">
            <h3 style="margin: 0; color: #85929e; font-size: 1em; text-transform: uppercase;">⏳ Stale Check-in</h3>
            <div style="font-size: 2.2em; font-weight: 800; color: #f39c12; margin-top: 10px;"><?= $stale_checkin_count ?></div>
            <p style="margin: 5px 0 0 0; color: #7f8c8d; font-size: 0.8em;">Hosts with last check-in older than 3 days or N/D.</p>
        </div>

        <div class="card" style="border-left: 4px solid #2ecc71;">
            <h3 style="margin: 0; color: #85929e; font-size: 1em; text-transform: uppercase;">✅ Healthy Hosts</h3>
            <div style="font-size: 2.2em; font-weight: 800; color: #2ecc71; margin-top: 10px;"><?= $total_rhel10 - $stale_checkin_count ?></div>
            <p style="margin: 5px 0 0 0; color: #7f8c8d; font-size: 0.8em;">Hosts with recent check-in within the last 3 days.</p>
        </div>

        <div class="card" style="border-left: 4px solid #9b59b6;">
            <h3 style="margin: 0; color: #85929e; font-size: 1em; text-transform: uppercase;">📦 Minor Releases</h3>
            <div style="font-size: 2.2em; font-weight: 800; color: #9b59b6; margin-top: 10px;"><?= count($minor_distribution) ?></div>
            <p style="margin: 5px 0 0 0; color: #7f8c8d; font-size: 0.8em;">Distinct RHEL 10 minor versions detected in inventory.</p>
        </div>

    </div>

    <!-- DISTRIBUZIONE PER MINOR RELEASE -->
    <?php if (!empty($minor_distribution)): ?>
    <div class="card" style="margin-bottom: 25px;">
        <h3 style="margin: 0 0 15px 0; color: #85929e; font-size: 1em; text-transform: uppercase;">📊 Distribution by Minor Release</h3>
        <div style="display: flex; gap: 12px; flex-wrap: wrap;">
            <?php foreach ($minor_distribution as $os_name => $count): ?>
            <div style="background: rgba(44,62,80,0.3); border: 1px solid rgba(133,193,233,0.2); border-radius: 6px; padding: 10px 16px; min-width: 140px; text-align: center;">
                <div style="font-size: 11px; color: #7f8c8d; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.4px;"><?= htmlspecialchars($os_name) ?></div>
                <div style="font-size: 22px; font-weight: 700; color: #85c1e9;"><?= $count ?></div>
                <div style="font-size: 11px; color: #566573; margin-top: 2px;"><?= round($count / $total_rhel10 * 100, 1) ?>%</div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- TABELLA HOST -->
    <div class="card">
        <h3 style="margin: 0 0 5px 0; color: #fff; font-size: 1.1em;">
            🔵 RHEL 10 Host Inventory
            <span style="font-size: 0.7em; font-weight: 400; color: #7f8c8d; margin-left: 10px;">
                <?= $total_rhel10 ?> hosts
            </span>
        </h3>
        <p style="margin: 0 0 15px 0; color: #7f8c8d; font-size: 0.85em;">Grouped by minor release — sorted by version then kernel</p>

        <?php if (empty($satellite_hosts)): ?>
            <div style="text-align: center; padding: 40px; color: #7f8c8d;">
                <div style="font-size: 2em; margin-bottom: 10px;">🔵</div>
                <div>No RHEL 10 hosts found in Satellite inventory.</div>
                <div style="font-size: 0.85em; margin-top: 6px; color: #566573;">Check that the OS field in Satellite contains "Red Hat Enterprise Linux 10"</div>
            </div>
        <?php else: ?>

        <?php
        // Raggruppa per minor release per la visualizzazione
        $grouped = [];
        foreach ($satellite_hosts as $host) {
            $key = $host['os'] ?? 'Unknown RHEL 10';
            $grouped[$key][] = $host;
        }
        ?>

        <?php foreach ($grouped as $os_label => $hosts): ?>

            <div class="minor-group-header">
                <span>📦 <?= htmlspecialchars($os_label) ?></span>
                <span class="badge-count"><?= count($hosts) ?> hosts</span>
            </div>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Hostname</th>
                            <th>IP Address</th>
                            <th>Kernel</th>
                            <th>CV Environment</th>
                            <th>Location</th>
                            <th>Last Check-in</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $counter = 1;
                    foreach ($hosts as $host):
                        $ip_value  = trim($host['ip'] ?? '');
                        $ip_missing = empty($ip_value) || $ip_value === 'N/A' || $ip_value === 'N/D';
                        $ip_style   = $ip_missing ? 'color: #e74c3c; font-weight: bold;' : 'color: #85c1e9;';

                        // Check-in stale check
                        $lc = trim($host['last_checkin'] ?? '');
                        $is_stale   = false;
                        $checkin_display = $lc ?: 'N/D';
                        $checkin_style   = '';
                        if (empty($lc) || $lc === 'N/A' || $lc === 'N/D') {
                            $is_stale      = true;
                            $checkin_display = 'N/D';
                            $checkin_style   = 'color: #e74c3c; font-weight: bold;';
                        } else {
                            $lc_obj = DateTime::createFromFormat('Y-m-d', substr($lc, 0, 10));
                            if ($lc_obj && $lc_obj < $thirty_days_ago) {
                                $is_stale    = true;
                                $checkin_style = 'color: #e74c3c; font-weight: bold;';
                            }
                        }
                    ?>
                    <tr class="<?= $is_stale ? 'stale-row' : '' ?>">
                        <td style="color: #566573;"><?= $counter++ ?></td>
                        <td class="nowrap-cell" style="color: #e5e8e8; font-weight: 500;">
                            <?= htmlspecialchars($host['hostname'] ?? '') ?>
                        </td>
                        <td style="font-family: monospace; <?= $ip_style ?>" class="nowrap-cell">
                            <?= $ip_missing ? '⚠️ ' : '' ?><?= htmlspecialchars($ip_value ?: 'N/A') ?>
                        </td>
                        <td style="font-family: monospace; color: #85c1e9; font-size: 0.88em;" class="nowrap-cell">
                            ⚙️ <?= htmlspecialchars($host['kernel'] ?? 'N/A') ?>
                        </td>
                        <td class="nowrap-cell">
                            <span style="font-family: monospace; font-size: 0.9em; color: #abb2b9;">
                                <?= htmlspecialchars($host['content_view_environment'] ?? 'N/A') ?>
                            </span>
                        </td>
                        <td class="nowrap-cell">
                            <span style="padding: 2px 6px; background-color: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 4px; font-size: 0.85em; color: #e5e8e8;">
                                📍 <?= htmlspecialchars($host['location'] ?? 'N/A') ?>
                            </span>
                        </td>
                        <td style="<?= $checkin_style ?>" class="nowrap-cell">
                            <?= $is_stale ? '✕ ' : '' ?><?= htmlspecialchars($checkin_display) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endforeach; ?>

        <?php endif; ?>
    </div>

</div>
</body>
</html>
