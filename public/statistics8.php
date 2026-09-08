<?php
require __DIR__ . '/../app/bootstrap.php';
$current_user = require_login($pdo);

// 2. Data Extraction & Protected Numerical Sorting (Handles 8.10 > 8.9 and ignores corrupted text)
$satellite_hosts = [];
try {
    $stmt = $pdo->prepare("
        SELECT hostname, os, ip, kernel, content_view_environment, location, last_checkin
        FROM inventory_satellite
        WHERE (
            os ILIKE '%RedHat 8%'
            OR os ILIKE '%RHEL Server 8%'
            OR os ILIKE '%RHEL 8%'
            OR os ILIKE '%Red Hat%8%'
          )
          AND status = 'active'
        ORDER BY
            CASE
                WHEN regexp_replace(os, '^[^0-9]*8\.([0-9]+).*$', '\1') ~ '^[0-9]+$'
                THEN CAST(regexp_replace(os, '^[^0-9]*8\.([0-9]+).*$', '\1') AS INTEGER)
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
$total_rhel8 = count($satellite_hosts);

// Count hosts missing a valid IP address
$missing_ip_count = 0;
foreach ($satellite_hosts as $host) {
    $ip = trim($host['ip'] ?? '');
    if (empty($ip) || $ip === 'N/A' || $ip === 'N/D') {
        $missing_ip_count++;
    }
}

// Group metrics by minor release to build the breakdown chart
$minor_distribution = [];
foreach ($satellite_hosts as $host) {
    $os_name = $host['os'] ?? 'Unknown RHEL 8';
    $minor_distribution[$os_name] = ($minor_distribution[$os_name] ?? 0) + 1;
}
uksort($minor_distribution, 'strnatcasecmp');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Satellite Statistics - RHEL 8</title>
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

        .minor-group-header { background-color: rgba(52, 152, 219, 0.1); color: #3498db; font-weight: bold; font-size: 1em; padding: 10px 15px; margin-top: 30px; border-left: 4px solid #3498db; border-radius: 0 4px 4px 0; display: flex; justify-content: space-between; }
        .badge-count { background: #3498db; color: white; padding: 2px 8px; border-radius: 10px; font-size: 0.8em; }
    </style>
</head>
<body>

<div class="container">

    <div class="header-panel">
        <div>
            <h1 style="margin: 0; font-size: 1.8em; color: #fff;">📈 Satellite Analytics - RHEL 8 Infrastructure</h1>
            <p style="margin: 5px 0 0 0; color: #7f8c8d; font-size: 0.95em;">Dedicated overview for active Red Hat Enterprise Linux 8 environments</p>
        </div>
        <div>
            <a href="index.php" class="btn-back">⬅️ Back to Dashboard</a>
        </div>
    </div>

    <div class="stats-row">
        <div class="card" style="border-left: 4px solid #3498db;">
            <h3 style="margin: 0; color: #85929e; font-size: 1em; text-transform: uppercase;">Total RHEL 8 Hosts</h3>
            <div style="font-size: 2.2em; font-weight: 800; color: #3498db; margin-top: 10px;"><?= $total_rhel8 ?></div>
            <p style="margin: 5px 0 0 0; color: #7f8c8d; font-size: 0.8em;">Production mainline assets. Tracking minor distribution for patching windows.</p>
        </div>

        <div class="card" style="border-left: 4px solid #e74c3c;">
            <h3 style="margin: 0; color: #85929e; font-size: 1em; text-transform: uppercase;">⚠️ Agent Health Anomalies</h3>
            <div style="font-size: 2.2em; font-weight: 800; color: #e74c3c; margin-top: 10px;"><?= $missing_ip_count ?></div>
            <p style="margin: 5px 0 0 0; color: #7f8c8d; font-size: 0.8em;">Active systems missing a registered IP Address. Subscription or facter check required.</p>
        </div>

        <div class="card">
            <h3 style="margin: 0; color: #85929e; font-size: 1em; text-transform: uppercase;">Minor Release Breakdown</h3>
            <div style="display: flex; gap: 15px; margin-top: 12px; flex-wrap: wrap;">
                <?php foreach ($minor_distribution as $version => $count): ?>
                    <div style="background: rgba(0,0,0,0.2); padding: 5px 12px; border-radius: 4px; border: 1px solid rgba(255,255,255,0.03); font-size: 0.85em;">
                        <span style="color: #abb2b9;"><?= htmlspecialchars($version) ?>:</span>
                        <strong style="color: #fff;"><?= $count ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="card" style="padding: 25px;">
        <h2 style="margin-top: 0; color: #fff; font-size: 1.3em;">📋 Red Hat 8 Inventory (Sorted by Minor &amp; Kernel)</h2>
        <p style="color: #7f8c8d; font-size: 0.85em; margin-bottom: 10px;">Displaying native Satellite records ordered sequentially by minor release and package kernel version.</p>

        <?php
        if (empty($satellite_hosts)):
            echo '<p style="color: #e74c3c; font-weight: bold; margin-top: 20px;">⚠️ No RHEL 8 hosts detected on Satellite.</p>';
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
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Hostname</th>
                                <th>Operating System</th>
                                <th>IP Address</th>
                                <th>Kernel Version (Ascending)</th>
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
                                    if ($checkin_obj && $checkin_obj < (new DateTime())->modify('-3 days')) {
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
                                    <td class="nowrap-cell"><span style="color: #3498db; font-size: 0.9em;">🔷 <?= htmlspecialchars($sat['os'] ?? '') ?></span></td>

                                    <td style="font-family: monospace; <?= $ip_style ?>" class="nowrap-cell">
                                        <?= ($ip_value === 'N/A' || $ip_value === 'N/D' || empty($ip_value)) ? '⚠️ ' : '' ?>
                                        <?= htmlspecialchars($ip_value) ?>
                                    </td>

                                    <td style="font-family: monospace; color: #3498db; background-color: rgba(52,152,219,0.03); padding-left: 8px; border-radius: 3px;" class="nowrap-cell">
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
                                        <?= htmlspecialchars($sat['last_checkin'] ?? 'N/D') ?>
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

</body>
</html>
