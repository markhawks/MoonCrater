<?php
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Domain/inventory.php';
require_once __DIR__ . '/../app/Domain/inventory.php';
$current_user = require_login($pdo);

// 2. Data Extraction & Protected Numerical Sorting (Handles 9.10 > 9.9 and ignores corrupted text)
$satellite_hosts = [];
try {
    $stmt = $pdo->prepare("
        SELECT hostname, os, ip, kernel, content_view_environment, location, last_checkin
        FROM inventory_satellite
        WHERE (
            os ILIKE '%RedHat 9%'
            OR os ILIKE '%RHEL Server 9%'
            OR os ILIKE '%RHEL 9%'
            OR os ILIKE '%Red Hat%9%'
          )
          AND status IN ('active', 'unhealthy')
        ORDER BY
            CASE
                WHEN regexp_replace(os, '^[^0-9]*9\.([0-9]+).*$', '\1') ~ '^[0-9]+$'
                THEN CAST(regexp_replace(os, '^[^0-9]*9\.([0-9]+).*$', '\1') AS INTEGER)
                ELSE 999
            END ASC,
            kernel ASC
    ");
    $stmt->execute();
    $satellite_hosts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Statistics query failed: " . $e->getMessage()); http_response_code(500); exit("Unable to load statistics.");
}

// 3. Quick KPI Calculations
$total_rhel9 = count($satellite_hosts);

// Count hosts missing a valid IP address
$missing_ip_count = 0;
$missing_cv_env_count = 0;
$stale_checkin_count = 0;
$checkin_limit = (new DateTime())->modify('-' . SATELLITE_CHECKIN_MAX_AGE_DAYS . ' days');
foreach ($satellite_hosts as $host) {
    $lastCheckin = trim($host['last_checkin'] ?? '');
    $checkinDate = DateTime::createFromFormat('Y-m-d', substr($lastCheckin, 0, 10));
    if ($lastCheckin === '' || in_array($lastCheckin, ['N/A', 'N/D'], true)
        || ($checkinDate && $checkinDate < $checkin_limit)) {
        $stale_checkin_count++;
    }
    $cvEnv = strtoupper(trim($host['content_view_environment'] ?? ''));
    if (in_array($cvEnv, ['', 'N/A', 'N/D', 'NULL'], true)) {
        $missing_cv_env_count++;
    }
    $ip = trim($host['ip'] ?? '');
    if (empty($ip) || $ip === 'N/A' || $ip === 'N/D') {
        $missing_ip_count++;
    }
}

// Group metrics by minor release to build the breakdown chart
$minor_distribution = [];
foreach ($satellite_hosts as $host) {
    $os_name = $host['os'] ?? 'Unknown RHEL 9';
    $minor_distribution[$os_name] = ($minor_distribution[$os_name] ?? 0) + 1;
}
uksort($minor_distribution, 'strnatcasecmp');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Satellite Statistics - RHEL 9</title>
    <link rel="icon" type="image/png" sizes="any" href="assets/img/favicon-mooncrater-130.png">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { background-color: #1a252f; color: #e5e8e8; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 0; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        .header-panel { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .stats-row { display: grid; gap: 20px; margin-bottom: 25px; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); }
        .card { background-color: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05); padding: 20px; border-radius: 6px; flex: 1; }
        .btn-back { background-color: rgba(255,255,255,0.1); color: #fff; padding: 8px 15px; border-radius: 4px; text-decoration: none; font-weight: bold; font-size: 0.9em; transition: background 0.2s; }
        .btn-back:hover { background-color: rgba(255,255,255,0.2); }

        .table-container {
            width: 100%;
            overflow-x: auto;
            margin-top: 15px;
            background-color: rgba(0,0,0,0.1);
            border-radius: 4px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            overflow: hidden;
            table-layout: auto;
        }
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

        .nowrap-cell {
            white-space: nowrap !important;
        }

        .minor-group-header { background-color: rgba(46, 204, 113, 0.1); color: #2ecc71; font-weight: bold; font-size: 1em; padding: 10px 15px; margin-top: 30px; border-left: 4px solid #2ecc71; border-radius: 0 4px 4px 0; display: flex; justify-content: space-between; }
        .badge-count { background: #2ecc71; color: white; padding: 2px 8px; border-radius: 10px; font-size: 0.8em; }
    </style>
</head>
<body>

<div class="container">

    <div class="header-panel">
        <div>
            <h1 style="margin: 0; font-size: 1.8em; color: #fff;">🚀 Satellite Analytics - RHEL 9 Deployment</h1>
            <p style="margin: 5px 0 0 0; color: #7f8c8d; font-size: 0.95em;">Dedicated statistics for bleeding-edge Red Hat Enterprise Linux 9 environments</p>
        </div>
        <div>
            <a href="index.php" class="btn-back">⬅️ Back to Dashboard</a>
        </div>
    </div>

    <div class="stats-row">
        <div class="card" style="border-left: 4px solid #2ecc71;">
            <h3 style="margin: 0; color: #85929e; font-size: 1em; text-transform: uppercase;">Total RHEL 9 Hosts</h3>
            <div style="font-size: 2.2em; font-weight: 800; color: #2ecc71; margin-top: 10px;"><?= $total_rhel9 ?></div>
            <p style="margin: 5px 0 0 0; color: #7f8c8d; font-size: 0.8em;">Next-generation servers. Verifying baseline kernel parameters and lifecycle compliance.</p>
        </div>

        <div class="card" style="border-left: 4px solid #e74c3c;">
            <h3 style="margin: 0; color: #85929e; font-size: 1em; text-transform: uppercase;">⚠️ Agent Health Anomalies</h3>
            <dl style="display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:8px 12px;margin:12px 0 0;color:#e5e8e8;">
                <dt>Missing CV/ENV</dt>
                <dd style="margin:0;text-align:right;font-size:1.25em;font-weight:700;color:#f5b041;"><?= $missing_cv_env_count ?></dd>
                <dt>Missing IP Address</dt>
                <dd style="margin:0;text-align:right;font-size:1.25em;font-weight:700;color:#f5b041;"><?= $missing_ip_count ?></dd>
            </dl>
        </div>

        <div class="card" style="border-left: 4px solid #f39c12;">
            <h3 style="margin: 0; color: #85929e; font-size: 1em; text-transform: uppercase;">⏳ Stale Check-in</h3>
            <div style="font-size: 2.2em; font-weight: 800; color: #f39c12; margin-top: 10px;"><?= $stale_checkin_count ?></div>
            <p style="margin: 5px 0 0 0; color: #7f8c8d; font-size: 0.8em;">Hosts with a last check-in older than <?= SATELLITE_CHECKIN_MAX_AGE_DAYS ?> days or unavailable.</p>
        </div>

        <div class="card" style="border-left: 4px solid #2ecc71;">
            <h3 style="margin: 0; color: #85929e; font-size: 1em; text-transform: uppercase;">✅ Healthy Hosts</h3>
            <div style="font-size: 2.2em; font-weight: 800; color: #2ecc71; margin-top: 10px;"><?= $total_rhel9 - $stale_checkin_count ?></div>
            <p style="margin: 5px 0 0 0; color: #7f8c8d; font-size: 0.8em;">Hosts with recent check-in within the last <?= SATELLITE_CHECKIN_MAX_AGE_DAYS ?> days.</p>
        </div>

        <div class="card" style="border-left: 4px solid #9b59b6;">
            <h3 style="margin: 0; color: #85929e; font-size: 1em; text-transform: uppercase;">📦 Minor Releases</h3>
            <div style="font-size: 2.2em; font-weight: 800; color: #9b59b6; margin-top: 10px;"><?= count($minor_distribution) ?></div>
            <p style="margin: 5px 0 0 0; color: #7f8c8d; font-size: 0.8em;">Distinct RHEL 9 minor versions detected in inventory.</p>
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
                <div style="font-size: 11px; color: #566573; margin-top: 2px;"><?= round($count / $total_rhel9 * 100, 1) ?>%</div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="card" style="padding: 25px;">
        <h2 style="margin-top: 0; color: #fff; font-size: 1.3em;">📋 Red Hat 9 Inventory (Sorted by Minor &amp; Kernel)</h2>
        <p style="color: #7f8c8d; font-size: 0.85em; margin-bottom: 10px;">Displaying native Satellite records ordered sequentially by minor release and package kernel version.</p>

        <?php
        if (empty($satellite_hosts)):
            echo '<p style="color: #e74c3c; font-weight: bold; margin-top: 20px;">⚠️ No RHEL 9 hosts detected on Satellite.</p>';
        else:
            $grouped_hosts = [];
            foreach ($satellite_hosts as $host) {
                $grouped_hosts[$host['os']][] = $host;
            }

            $global_counter = 1;
            foreach ($grouped_hosts as $minor_name => $hosts_in_minor):
            ?>
                <div class="minor-group-header">
                    <span>📦 <?= htmlspecialchars($minor_name) ?></span>
                    <span class="badge-count"><?= count($hosts_in_minor) ?> hosts</span>
                </div>

                <div class="table-container">
                    <table data-rhel-sortable>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Hostname</th>
                                <th>Operating System</th>
                                <th>IP Address</th>
                                <th>Kernel Version</th>
                                <th>Content View Env</th>
                                <th>Location</th>
                                <th>Last Check-in</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($hosts_in_minor as $sat):
                                // Check-in threshold check
                                $checkin_class = '';
                                if (!empty($sat['last_checkin']) && $sat['last_checkin'] !== 'N/A') {
                                    $checkin_date_part = substr($sat['last_checkin'], 0, 10);
                                    $checkin_obj = DateTime::createFromFormat('Y-m-d', $checkin_date_part);
                                    if ($checkin_obj && $checkin_obj < (new DateTime())->modify('-' . SATELLITE_CHECKIN_MAX_AGE_DAYS . ' days')) {
                                        $checkin_class = 'color: #e67e22; font-weight: bold;';
                                    }
                                }

                                // IP anomaly check
                                $ip_value = trim($sat['ip'] ?? 'N/A');
                                $ip_style = 'color: #ced4da;';
                                if ($ip_value === 'N/A' || $ip_value === 'N/D' || empty($ip_value)) {
                                    $ip_style = 'color: #e67e22; font-weight: bold; background-color: rgba(230, 126, 34, 0.05); padding: 4px 8px; border-radius: 3px;';
                                }
                            ?>
                                <tr>
                                    <td style="color: #7f8c8d;" class="nowrap-cell"><?= $global_counter++ ?></td>
                                    <td style="font-weight: bold; color: #fff;" class="nowrap-cell"><?= htmlspecialchars($sat['hostname'] ?? '') ?></td>
                                    <td class="nowrap-cell"><span style="color: #2ecc71; font-size: 0.9em;">🟩 <?= htmlspecialchars($sat['os'] ?? '') ?></span></td>

                                    <td style="font-family: monospace; <?= $ip_style ?>" class="nowrap-cell">
                                        <?= ($ip_value === 'N/A' || $ip_value === 'N/D' || empty($ip_value)) ? '⚠️ ' : '' ?>
                                        <?= htmlspecialchars($ip_value) ?>
                                    </td>

                                    <td style="font-family: monospace; color: #2ecc71; background-color: rgba(46,204,113,0.03); padding-left: 8px; border-radius: 3px;" class="nowrap-cell">
                                        ⚙️ <?= htmlspecialchars($sat['kernel'] ?? 'N/A') ?>
                                    </td>
                                    <td class="nowrap-cell">
                                        <span style="font-family: monospace; font-size: 0.9em; color: #abb2b9;">
                                            <?= htmlspecialchars($sat['content_view_environment'] ?? 'N/A') ?>
                                        </span>
                                    </td>
                                    <td class="nowrap-cell">
                                        <span style="padding: 2px 6px; background-color: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 4px; font-size: 0.85em; color: #e5e8e8; font-weight: bold;">
                                            📍 <?= htmlspecialchars($sat['location'] ?? 'N/A') ?>
                                        </span>
                                    </td>
                                    <td style="<?= $checkin_class ?>" class="nowrap-cell">
                                        <?= htmlspecialchars($sat['last_checkin'] ?? 'N/A') ?>
                                        <?= !empty($checkin_class) ? '⏳' : '' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php
            endforeach;
        endif;
        ?>
    </div>
</div>

<script src="assets/js/rhel-table-sort.js" defer></script>
</body>
</html>
