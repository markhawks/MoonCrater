<?php
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Domain/inventory.php';
$current_user = require_login($pdo);
$is_admin = (($current_user["role"] ?? "user") === "admin");

// Data Extraction via LEFT JOIN
$zabbix_hosts = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            z.hostname as zabbix_hostname,
            z.role,
            z.zabbix_version,
            z.last_update as customer_list_date,
            s.hostname as satellite_hostname,
            s.os,
            s.ip,
            s.kernel,
            s.content_view_environment,
            s.location,
            s.last_checkin
        FROM zabbix_hosts z
        LEFT JOIN inventory_satellite s
            ON LOWER(TRIM(z.hostname)) = LOWER(TRIM(s.hostname))
            AND s.status = 'active'
        ORDER BY z.role DESC, z.hostname ASC
    ");
    $stmt->execute();
    $zabbix_hosts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Statistics query failed: " . $e->getMessage()); http_response_code(500); exit("Unable to load statistics.");
}

// KPI
$total_zabbix               = count($zabbix_hosts);
$core_servers               = 0;
$proxies                    = 0;
$missing_ip_count           = 0;
$missing_on_satellite_count = 0;
$migrated_zabbix7           = 0;   // host con zabbix_version popolata

foreach ($zabbix_hosts as $host) {
    if ($host['role'] === 'Core Server') $core_servers++;
    if ($host['role'] === 'Proxy')       $proxies++;
    if (empty($host['satellite_hostname'])) {
        $missing_on_satellite_count++;
    } else {
        $ip = trim($host['ip'] ?? '');
        if (empty($ip) || $ip === 'N/A' || $ip === 'N/D') $missing_ip_count++;
    }
    $zv = trim($host['zabbix_version'] ?? '');
    if (!empty($zv) && $zv !== 'N/A') $migrated_zabbix7++;
}
$migration_pct = $total_zabbix > 0
    ? round($migrated_zabbix7 / $total_zabbix * 100, 1)
    : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zabbix Statistics — MoonCrater</title>
    <link rel="icon" type="image/png" sizes="any" href="assets/img/favicon-mooncrater-130.png">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { background-color: #1a252f; color: #e5e8e8; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 0; padding: 20px; box-sizing: border-box; width: 100%; }
        .container { width: 100%; box-sizing: border-box; }

        .header-panel { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .btn-back { background-color: rgba(255,255,255,0.1); color: #fff; padding: 8px 15px; border-radius: 4px; text-decoration: none; font-weight: bold; font-size: 0.9em; transition: background 0.2s; }
        .btn-back:hover { background-color: rgba(255,255,255,0.2); }

        .stats-row { display: flex; gap: 20px; margin-bottom: 25px; flex-wrap: nowrap; }
        .card { background-color: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05); padding: 20px; border-radius: 6px; flex: 1; }

        .table-container { width: 100%; overflow-x: auto; margin-top: 15px; background-color: rgba(0,0,0,0.1); border-radius: 4px; }
        table { width: 100%; border-collapse: collapse; table-layout: auto; }
        th { padding: 12px; text-align: left; color: #85929e; border-bottom: 2px solid rgba(255,255,255,0.05); font-weight: 600; font-size: 0.9em; white-space: nowrap; }
        td { padding: 10px 12px; border-bottom: 1px solid rgba(255,255,255,0.02); font-size: 0.9em; vertical-align: middle; }
        tr:hover { background-color: rgba(255,255,255,0.02); }
        .nowrap-cell { white-space: nowrap !important; }

        .role-core  { background-color: rgba(155,89,182,0.2); color: #9b59b6; padding: 3px 8px; border-radius: 4px; font-weight: bold; font-size: 0.85em; }
        .role-proxy { background-color: rgba(52,152,219,0.2); color: #3498db; padding: 3px 8px; border-radius: 4px; font-weight: bold; font-size: 0.85em; }

        tr.missing-satellite-row { background-color: rgba(231,76,60,0.08) !important; border-left: 3px solid #e74c3c; }
        .text-missing { color: #e74c3c !important; font-weight: bold; font-style: italic; font-size: 0.85em; }

        /* --- Azioni riga --- */
        .row-actions { display: flex; gap: 5px; align-items: center; }

        .btn-edit, .btn-delete, .btn-save, .btn-cancel {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 4px 9px; border-radius: 4px; font-size: 11px;
            font-weight: 600; cursor: pointer; border: 1px solid;
            transition: all 0.15s; white-space: nowrap; font-family: inherit;
        }
        .btn-edit   { background: rgba(52,152,219,0.1);  border-color: rgba(52,152,219,0.35);  color: #3498db; }
        .btn-edit:hover { background: rgba(52,152,219,0.22); }
        .btn-delete { background: rgba(231,76,60,0.08);  border-color: rgba(231,76,60,0.3);   color: #e74c3c; }
        .btn-delete:hover { background: rgba(231,76,60,0.2); }
        .btn-save   { background: rgba(46,204,113,0.1);  border-color: rgba(46,204,113,0.35); color: #2ecc71; }
        .btn-save:hover { background: rgba(46,204,113,0.22); }
        .btn-cancel { background: rgba(255,255,255,0.05); border-color: rgba(255,255,255,0.15); color: #7f8c8d; }
        .btn-cancel:hover { background: rgba(255,255,255,0.1); }

        /* Input inline edit */
        .inline-input {
            background: rgba(255,255,255,0.07);
            border: 1px solid rgba(52,152,219,0.4);
            border-radius: 4px; color: #fff;
            padding: 5px 8px; font-size: 13px;
            font-family: inherit; outline: none;
            width: 220px;
        }
        .inline-input:focus { border-color: rgba(52,152,219,0.8); background: rgba(52,152,219,0.08); }

        .inline-select {
            background: rgba(255,255,255,0.07);
            border: 1px solid rgba(52,152,219,0.4);
            border-radius: 4px; color: #fff;
            padding: 5px 8px; font-size: 12px;
            font-family: inherit; outline: none; cursor: pointer;
        }
        .inline-select option { background: #2f3640; }

        /* Riga aggiunta nuovo host */
        tr.add-row td { background: rgba(46,204,113,0.04); border-top: 2px solid rgba(46,204,113,0.2); padding: 12px; }

        .btn-add {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 7px 14px; border-radius: 4px; font-size: 12px;
            font-weight: 700; cursor: pointer;
            background: rgba(46,204,113,0.12);
            border: 1px solid rgba(46,204,113,0.35);
            color: #2ecc71; font-family: inherit;
            transition: background 0.15s;
        }
        .btn-add:hover { background: rgba(46,204,113,0.25); }

        /* IP nascosto di default — kernel e CV sempre visibili */
        .col-ip     { display: none; }
        .col-hidden { display: none !important; }

        /* Toggle toolbar */
        .col-toggle-bar {
            display: flex; align-items: center; gap: 16px;
            flex-wrap: wrap; margin-bottom: 14px;
            padding: 10px 14px;
            background: rgba(255,255,255,0.02);
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 6px;
        }
        .col-toggle-bar span {
            font-size: 11px; font-weight: 700;
            color: #85929e; text-transform: uppercase; letter-spacing: 0.4px;
        }
        .toggle-checkbox-label {
            display: flex; align-items: center; gap: 6px;
            font-size: 12px; color: #d5dbdb; cursor: pointer; user-select: none;
        }
        .toggle-checkbox-label input { cursor: pointer; }
        #toast {
            position: fixed; bottom: 24px; right: 24px;
            background: #1e272e; border: 1px solid rgba(255,255,255,0.1);
            border-radius: 6px; padding: 12px 18px;
            font-size: 13px; color: #e5e8e8;
            box-shadow: 0 4px 20px rgba(0,0,0,0.4);
            opacity: 0; transition: opacity 0.3s;
            z-index: 9999; pointer-events: none;
        }
        #toast.show { opacity: 1; }
        #toast.ok   { border-color: rgba(46,204,113,0.5); color: #2ecc71; }
        #toast.err  { border-color: rgba(231,76,60,0.5);  color: #e74c3c; }
    </style>
    <script>window.CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;</script>
<?php if (!$is_admin): ?><style>.btn-edit,.btn-delete,.btn-save,.btn-cancel,.btn-add,.add-row { display:none !important; }.view-zver { pointer-events:none; cursor:default !important; }</style><?php endif; ?>
</head>
<body>
<div id="toast"></div>

<div class="container">

    <!-- HEADER -->
    <div class="header-panel">
        <div>
            <h1 style="margin:0; font-size:1.8em; color:#fff;">📊 Zabbix Infrastructure — Satellite Live Telemetry</h1>
            <p style="margin:5px 0 0 0; color:#7f8c8d; font-size:0.95em;">Cross-referenced inventory of Zabbix nodes tracked on Red Hat Satellite</p>
        </div>
        <div>
            <a href="index.php" class="btn-back">⬅️ Back to Dashboard</a>
        </div>
    </div>

    <!-- KPI -->
    <div class="stats-row">
        <div class="card" style="border-left:4px solid #9b59b6;">
            <h3 style="margin:0; color:#85929e; font-size:1em; text-transform:uppercase;">Total Zabbix Assets</h3>
            <div style="font-size:2.2em; font-weight:800; color:#9b59b6; margin-top:10px;" id="kpi-total"><?= $total_zabbix ?></div>
            <p style="margin:5px 0 0; color:#7f8c8d; font-size:0.8em;">All nodes declared in customer list.</p>
        </div>
        <div class="card" style="border-left:4px solid #3498db;">
            <h3 style="margin:0; color:#85929e; font-size:1em; text-transform:uppercase;">Infrastructure Roles</h3>
            <div style="font-size:1.4em; font-weight:700; color:#fff; margin-top:8px;">
                👑 Core Servers: <span style="color:#9b59b6;" id="kpi-core"><?= $core_servers ?></span>
            </div>
            <div style="font-size:1.4em; font-weight:700; color:#fff; margin-top:5px;">
                ⚙️ Proxies: <span style="color:#3498db;" id="kpi-proxy"><?= $proxies ?></span>
            </div>
        </div>
        <div class="card" style="border-left:4px solid #e74c3c;">
            <h3 style="margin:0; color:#85929e; font-size:1em; text-transform:uppercase;">❌ Missing on Satellite</h3>
            <div style="font-size:2.2em; font-weight:800; color:#e74c3c; margin-top:10px;" id="kpi-missing"><?= $missing_on_satellite_count ?></div>
            <p style="margin:5px 0 0; color:#7f8c8d; font-size:0.8em;">Zabbix servers not found in Satellite inventory.</p>
        </div>
        <div class="card" style="border-left:4px solid #e67e22;">
            <h3 style="margin:0; color:#85929e; font-size:1em; text-transform:uppercase;">⚠️ Agent IP Anomalies</h3>
            <div style="font-size:2.2em; font-weight:800; color:#e67e22; margin-top:10px;" id="kpi-ip"><?= $missing_ip_count ?></div>
            <p style="margin:5px 0 0; color:#7f8c8d; font-size:0.8em;">Matched nodes missing a valid IP address.</p>
        </div>
        <div class="card" style="border-left:4px solid #2ecc71;">
            <h3 style="margin:0; color:#85929e; font-size:1em; text-transform:uppercase;">🚀 Zabbix 7 Migration</h3>
            <div style="display:flex; align-items:baseline; gap:10px; margin-top:10px;">
                <div style="font-size:2.2em; font-weight:800; color:#2ecc71;" id="kpi-migrated"><?= $migrated_zabbix7 ?></div>
                <div style="font-size:1em; color:#566573;">/ <span id="kpi-total-z"><?= $total_zabbix ?></span></div>
            </div>
            <div style="margin-top:6px;">
                <div style="background:rgba(255,255,255,0.06); border-radius:4px; height:6px; overflow:hidden;">
                    <div id="kpi-bar" style="height:100%; width:<?= $migration_pct ?>%; background:#2ecc71; border-radius:4px; transition:width 0.4s;"></div>
                </div>
                <p style="margin:4px 0 0; color:#2ecc71; font-size:0.85em; font-weight:bold;" id="kpi-pct"><?= $migration_pct ?>% migrated to Zabbix 7</p>
            </div>
        </div>
    </div>

    <!-- TABELLA -->
    <div class="card" style="padding:25px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
            <h2 style="margin:0; color:#fff; font-size:1.3em;">📋 Verified Monitoring Nodes Inventory</h2>
            <span style="font-size:12px; color:#7f8c8d;">✏️ Click Edit to modify a row &nbsp;·&nbsp; ➕ Use the last row to add a new host</span>
        </div>

        <!-- Toggle colonne -->
        <div class="col-toggle-bar">
            <span>👁️ Show/Hide:</span>
            <label class="toggle-checkbox-label">
                <input type="checkbox" onchange="toggleZabbixCol('col-ip', this.checked)"> IP Address
            </label>
        </div>

        <div class="table-container">
            <table id="zabbix-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Hostname</th>
                        <th>Role</th>
                        <th>Zabbix Ver.</th>
                        <th>Operating System</th>
                        <th class="col-ip">IP Address</th>
                        <th>Kernel</th>
                        <th>CV Environment</th>
                        <th>Location</th>
                        <th>Last Check-in</th>
                        <th style="text-align:center;">Actions</th>
                    </tr>
                </thead>
                <tbody id="zabbix-tbody">

                <?php
                $counter = 1;
                foreach ($zabbix_hosts as $host):
                    $is_missing_sat = empty($host['satellite_hostname']);
                    $row_class      = $is_missing_sat ? 'missing-satellite-row' : '';

                    // Check-in stale
                    $checkin_style = '';
                    $checkin_val   = $host['last_checkin'] ?? '';
                    if (!$is_missing_sat && !empty($checkin_val) && $checkin_val !== 'N/A') {
                        $lc_obj = DateTime::createFromFormat('Y-m-d', substr($checkin_val, 0, 10));
                        if ($lc_obj && $lc_obj < (new DateTime())->modify('-' . SATELLITE_CHECKIN_MAX_AGE_DAYS . ' days')) {
                            $checkin_style = 'color:#e74c3c; font-weight:bold;';
                        }
                    }

                    // IP anomaly
                    $ip_val   = trim($host['ip'] ?? '');
                    $ip_style = '';
                    if (!$is_missing_sat && (empty($ip_val) || $ip_val === 'N/A' || $ip_val === 'N/D')) {
                        $ip_style = 'color:#e67e22; font-weight:bold;';
                        $ip_val   = 'N/A';
                    }
                ?>
                <tr class="<?= $row_class ?>" data-hostname="<?= htmlspecialchars($host['zabbix_hostname']) ?>" data-role="<?= htmlspecialchars($host['role']) ?>">
                    <td class="nowrap-cell td-counter" style="color:#7f8c8d;"><?= $counter++ ?></td>

                    <!-- Hostname (view / edit mode) -->
                    <td class="nowrap-cell">
                        <span class="view-hostname" style="font-weight:bold; color:#fff;">
                            <?= htmlspecialchars($host['zabbix_hostname']) ?>
                        </span>
                        <input class="edit-hostname inline-input" style="display:none;"
                               value="<?= htmlspecialchars($host['zabbix_hostname']) ?>">
                    </td>

                    <!-- Role -->
                    <td class="nowrap-cell">
                        <span class="view-role">
                            <span class="<?= $host['role'] === 'Core Server' ? 'role-core' : 'role-proxy' ?>">
                                <?= $host['role'] === 'Core Server' ? '👑 ' : '⚙️ ' ?><?= htmlspecialchars($host['role']) ?>
                            </span>
                        </span>
                        <select class="edit-role inline-select" style="display:none;">
                            <option value="Core Server" <?= $host['role'] === 'Core Server' ? 'selected' : '' ?>>👑 Core Server</option>
                            <option value="Proxy"       <?= $host['role'] === 'Proxy'       ? 'selected' : '' ?>>⚙️ Proxy</option>
                        </select>
                    </td>

                    <!-- Zabbix version — editabile inline -->
                    <?php
                        $zv = trim($host['zabbix_version'] ?? '');
                        $zv_display  = (!empty($zv) && $zv !== 'N/A') ? $zv : 'N/A';
                        $zv_migrated = (!empty($zv) && $zv !== 'N/A');
                        $zv_color    = $zv_migrated ? '#2ecc71' : '#e74c3c';
                        $zv_bg       = $zv_migrated ? 'rgba(46,204,113,0.1)' : 'rgba(231,76,60,0.08)';
                        $zv_border   = $zv_migrated ? 'rgba(46,204,113,0.35)' : 'rgba(231,76,60,0.3)';
                        $zv_icon     = $zv_migrated ? '✓ ' : '✕ ';
                    ?>
                    <td class="nowrap-cell td-zver">
                        <span class="view-zver" style="display:inline-flex; align-items:center; gap:4px; padding:3px 8px; border-radius:4px; border:1px solid <?= $zv_border ?>; background:<?= $zv_bg ?>; color:<?= $zv_color ?>; font-size:12px; font-weight:bold; cursor:pointer;" title="Click to edit" onclick="editZver(this)">
                            <?= $zv_icon ?><?= htmlspecialchars($zv_display) ?>
                        </span>
                        <span class="edit-zver-wrap" style="display:none;">
                            <input class="edit-zver inline-input" value="<?= htmlspecialchars($zv_display === 'N/A' ? '' : $zv_display) ?>" placeholder="e.g. 7.0" style="width:80px;">
                            <button class="btn-save" onclick="saveZver(this)" style="padding:3px 8px; font-size:11px;">✓</button>
                            <button class="btn-cancel" onclick="cancelZver(this)" style="padding:3px 8px; font-size:11px;">✕</button>
                        </span>
                    </td>

                    <!-- Satellite data columns -->
                    <td class="nowrap-cell td-os">
                        <?= $is_missing_sat ? '<span class="text-missing">Missing on Satellite</span>' : htmlspecialchars($host['os'] ?? 'N/A') ?>
                    </td>
                    <td class="nowrap-cell td-ip col-ip" style="font-family:monospace; <?= $ip_style ?>">
                        <?= $is_missing_sat ? '<span class="text-missing">⚠️ N/A</span>' : (($ip_val === 'N/A' ? '⚠️ ' : '') . htmlspecialchars($ip_val)) ?>
                    </td>
                    <td class="nowrap-cell td-kernel" style="font-family:monospace; color:#f39c12;">
                        <?= $is_missing_sat ? '<span class="text-missing">N/A</span>' : htmlspecialchars($host['kernel'] ?? 'N/A') ?>
                    </td>
                    <td class="nowrap-cell td-cv" style="font-family:monospace; font-size:0.9em; color:#7f8c8d;">
                        <?= $is_missing_sat ? '<span class="text-missing">N/A</span>' : htmlspecialchars($host['content_view_environment'] ?? 'N/A') ?>
                    </td>
                    <td class="nowrap-cell td-loc">
                        <?php if ($is_missing_sat): ?>
                            <span class="text-missing">N/A</span>
                        <?php else: ?>
                            <span style="padding:2px 6px; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); border-radius:4px; font-size:0.85em; font-weight:bold;">
                                📍 <?= htmlspecialchars($host['location'] ?? 'N/A') ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="nowrap-cell td-checkin" style="<?= $checkin_style ?>">
                        <?php if ($is_missing_sat): ?>
                            <span class="text-missing">N/A</span>
                        <?php else: ?>
                            <?= $checkin_style ? '✕ ' : '' ?><?= htmlspecialchars($checkin_val ?: 'N/D') ?>
                        <?php endif; ?>
                    </td>

                    <!-- Actions -->
                    <td style="text-align:center; white-space:nowrap;">
                        <div class="row-actions view-actions">
                            <button class="btn-edit"   onclick="startEdit(this)">✏️ Edit</button>
                            <button class="btn-delete" onclick="deleteHost(this)">🗑</button>
                        </div>
                        <div class="row-actions edit-actions" style="display:none;">
                            <button class="btn-save"   onclick="saveEdit(this)">✓ Save</button>
                            <button class="btn-cancel" onclick="cancelEdit(this)">✕</button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>

                </tbody>

                <!-- RIGA AGGIUNTA NUOVO HOST -->
                <tfoot>
                    <tr class="add-row">
                        <td style="color:#7f8c8d; font-size:12px;">new</td>
                        <td>
                            <input type="text" id="new-hostname" class="inline-input"
                                   placeholder="hostname or FQDN" style="width:240px;">
                        </td>
                        <td>
                            <select id="new-role" class="inline-select">
                                <option value="Core Server">👑 Core Server</option>
                                <option value="Proxy">⚙️ Proxy</option>
                            </select>
                        </td>
                        <td colspan="7" style="color:#566573; font-size:12px; font-style:italic;">
                            OS, IP, Kernel and other fields will be populated automatically from Satellite
                        </td>
                        <td style="text-align:center;">
                            <button class="btn-add" onclick="addHost()">➕ Add Host</button>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

</div><!-- /container -->


<script>
// ============================================================
// TOGGLE COLONNE
// ============================================================
function toggleZabbixCol(cls, visible) {
    // th: display table-cell, td: display '' (eredita dal CSS)
    document.querySelectorAll('th.' + cls).forEach(el => {
        el.style.display = visible ? 'table-cell' : 'none';
    });
    document.querySelectorAll('td.' + cls).forEach(el => {
        el.style.display = visible ? 'table-cell' : 'none';
    });
}

// ============================================================
// TOAST — feedback visivo non invasivo
// ============================================================
function showToast(msg, type = 'ok') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'show ' + type;
    clearTimeout(t._timer);
    t._timer = setTimeout(() => { t.className = ''; }, 3000);
}

// ============================================================
// EDIT — modalità inline
// ============================================================
function startEdit(btn) {
    const row = btn.closest('tr');
    row.querySelectorAll('.view-hostname, .view-role').forEach(el => el.style.display = 'none');
    row.querySelectorAll('.edit-hostname, .edit-role').forEach(el => el.style.display = 'inline-block');
    row.querySelector('.view-actions').style.display = 'none';
    row.querySelector('.edit-actions').style.display = 'flex';
    row.querySelector('.edit-hostname').focus();
}

function cancelEdit(btn) {
    const row = btn.closest('tr');
    // Ripristina i valori originali
    const origHostname = row.dataset.hostname;
    const origRole     = row.dataset.role;
    row.querySelector('.edit-hostname').value = origHostname;
    row.querySelector('.edit-role').value     = origRole;

    row.querySelectorAll('.view-hostname, .view-role').forEach(el => el.style.display = '');
    row.querySelectorAll('.edit-hostname, .edit-role').forEach(el => el.style.display = 'none');
    row.querySelector('.view-actions').style.display = 'flex';
    row.querySelector('.edit-actions').style.display = 'none';
}

async function saveEdit(btn) {
    const row         = btn.closest('tr');
    const old_hostname = row.dataset.hostname;
    const new_hostname = row.querySelector('.edit-hostname').value.trim().toLowerCase();
    const new_role     = row.querySelector('.edit-role').value;

    if (!new_hostname) { showToast('Hostname cannot be empty', 'err'); return; }

    btn.textContent = '...';
    btn.disabled = true;

    try {
        const res  = await fetch('action_zabbix.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN },
            body: JSON.stringify({ action: 'update', old_hostname, new_hostname, role: new_role })
        });
        const data = await res.json();

        if (!data.success) { showToast('Error: ' + data.error, 'err'); return; }

        // Aggiorna dati nel DOM
        row.dataset.hostname = new_hostname;
        row.dataset.role     = new_role;
        row.querySelector('.view-hostname').textContent = new_hostname;
        // IMPORTANTE: aggiorna anche l'input hidden così cancelEdit
        // non ripristina il vecchio hostname causando duplicati al prossimo save
        row.querySelector('.edit-hostname').value = new_hostname;
        row.querySelector('.edit-role').value     = new_role;

        const roleSpan = row.querySelector('.view-role span');
        roleSpan.className = new_role === 'Core Server' ? 'role-core' : 'role-proxy';
        roleSpan.textContent = (new_role === 'Core Server' ? '👑 ' : '⚙️ ') + new_role;

        // Aggiorna colonne Satellite se sono cambiate
        if (data.sat && data.sat.hostname) {
            row.querySelector('.td-os').textContent      = data.sat.os      || 'N/A';
            row.querySelector('.td-ip').textContent      = data.sat.ip      || 'N/A';
            row.querySelector('.td-kernel').textContent  = '⚙️ ' + (data.sat.kernel || 'N/A');
            row.querySelector('.td-cv').textContent      = data.sat.content_view_environment || 'N/A';
            row.querySelector('.td-loc').textContent     = '📍 ' + (data.sat.location || 'N/A');
            row.querySelector('.td-checkin').textContent = data.sat.last_checkin || 'N/D';
            row.classList.remove('missing-satellite-row');
        } else if (new_hostname !== old_hostname) {
            // Nuovo hostname non trovato su Satellite
            ['td-os','td-ip','td-kernel','td-cv','td-loc','td-checkin'].forEach(cls => {
                row.querySelector('.' + cls).innerHTML = '<span class="text-missing">Missing on Satellite</span>';
            });
            row.classList.add('missing-satellite-row');
        }

        cancelEdit(btn);
        showToast('✓ Host updated successfully');
        updateKPIs();

    } catch (e) {
        showToast('Network error', 'err');
    } finally {
        btn.textContent = '✓ Save';
        btn.disabled = false;
    }
}

// ============================================================
// DELETE
// ============================================================
async function deleteHost(btn) {
    const row      = btn.closest('tr');
    const hostname = row.dataset.hostname;

    if (!confirm('Remove "' + hostname + '" from the Zabbix inventory?\nThis cannot be undone.')) return;

    try {
        const res  = await fetch('action_zabbix.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN },
            body: JSON.stringify({ action: 'delete', hostname })
        });
        const data = await res.json();

        if (!data.success) { showToast('Error: ' + data.error, 'err'); return; }

        row.style.transition = 'opacity 0.3s';
        row.style.opacity    = '0';
        setTimeout(() => {
            row.remove();
            renumberRows();
            updateKPIs();
            showToast('🗑 Host removed: ' + hostname);
        }, 300);

    } catch (e) {
        showToast('Network error', 'err');
    }
}

// ============================================================
// ADD
// ============================================================
async function addHost() {
    const hostnameInput = document.getElementById('new-hostname');
    const roleInput     = document.getElementById('new-role');
    const hostname      = hostnameInput.value.trim().toLowerCase();
    const role          = roleInput.value;

    if (!hostname) { showToast('Please enter a hostname', 'err'); hostnameInput.focus(); return; }

    try {
        const res  = await fetch('action_zabbix.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN },
            body: JSON.stringify({ action: 'add', hostname, role })
        });
        const data = await res.json();

        if (!data.success) { showToast('Error: ' + data.error, 'err'); return; }

        showToast('Host added successfully');
        window.location.reload();
        return;

    } catch (e) {
        showToast('Network error', 'err');
    }
}

// ============================================================
// UTILITIES
// ============================================================
function renumberRows() {
    document.querySelectorAll('#zabbix-tbody tr .td-counter').forEach((td, i) => {
        td.textContent = i + 1;
    });
}

function updateKPIs() {
    const rows = document.querySelectorAll('#zabbix-tbody tr');
    let total = 0, core = 0, proxy = 0, missing = 0, migrated = 0;
    rows.forEach(row => {
        total++;
        if (row.dataset.role === 'Core Server') core++;
        if (row.dataset.role === 'Proxy')       proxy++;
        if (row.classList.contains('missing-satellite-row')) missing++;
        // Conta migrati: cerca lo span view-zver con testo != N/A e non inizia con ✕
        const zverSpan = row.querySelector('.view-zver');
        if (zverSpan && !zverSpan.textContent.includes('N/A') && zverSpan.textContent.trim() !== '') migrated++;
    });
    document.getElementById('kpi-total').textContent   = total;
    document.getElementById('kpi-core').textContent    = core;
    document.getElementById('kpi-proxy').textContent   = proxy;
    document.getElementById('kpi-missing').textContent = missing;

    // Migrazione Zabbix 7
    const pct = total > 0 ? Math.round(migrated / total * 1000) / 10 : 0;
    const el_m = document.getElementById('kpi-migrated');
    const el_z = document.getElementById('kpi-total-z');
    const el_p = document.getElementById('kpi-pct');
    const el_b = document.getElementById('kpi-bar');
    if (el_m) el_m.textContent = migrated;
    if (el_z) el_z.textContent = total;
    if (el_p) el_p.textContent = pct + '% migrated to Zabbix 7';
    if (el_b) el_b.style.width = pct + '%';
}

// ============================================================
// ZABBIX VERSION — edit inline sulla singola cella
// ============================================================
function editZver(span) {
    const td   = span.closest('.td-zver');
    span.style.display = 'none';
    td.querySelector('.edit-zver-wrap').style.display = 'inline-flex';
    td.querySelector('.edit-zver').focus();
    td.querySelector('.edit-zver').select();
}

function cancelZver(btn) {
    const td = btn.closest('.td-zver');
    td.querySelector('.edit-zver-wrap').style.display = 'none';
    td.querySelector('.view-zver').style.display = 'inline-flex';
}

async function saveZver(btn) {
    const td       = btn.closest('.td-zver');
    const row      = btn.closest('tr');
    const hostname = row.dataset.hostname;
    const val      = td.querySelector('.edit-zver').value.trim();
    const version  = val || null;  // null = cancella il valore (torna N/A)

    btn.textContent = '...';
    btn.disabled    = true;

    try {
        const res  = await fetch('action_zabbix.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN },
            body:    JSON.stringify({ action: 'update_zver', hostname, zabbix_version: version })
        });
        const data = await res.json();

        if (!data.success) { showToast('Error: ' + data.error, 'err'); return; }

        const migrated  = !!version;
        const display   = version || 'N/A';
        const color     = migrated ? '#2ecc71' : '#e74c3c';
        const bg        = migrated ? 'rgba(46,204,113,0.1)'  : 'rgba(231,76,60,0.08)';
        const border    = migrated ? 'rgba(46,204,113,0.35)' : 'rgba(231,76,60,0.3)';
        const icon      = migrated ? '✓ ' : '✕ ';

        const viewSpan = td.querySelector('.view-zver');
        viewSpan.textContent = icon + display;
        viewSpan.style.color      = color;
        viewSpan.style.background = bg;
        viewSpan.style.borderColor = border;

        cancelZver(btn);
        showToast(migrated ? '✓ Migrated to Zabbix ' + version : '✓ Version cleared');
        updateKPIs();

    } catch (e) {
        showToast('Network error', 'err');
    } finally {
        btn.textContent = '✓';
        btn.disabled    = false;
    }
}

// Enter key nel campo new-hostname lancia addHost
document.getElementById('new-hostname').addEventListener('keydown', e => {
    if (e.key === 'Enter') addHost();
});
</script>

</body>
</html>
