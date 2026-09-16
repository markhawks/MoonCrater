<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Domain/inventory.php';
require_once __DIR__ . '/../app/Domain/diff_inventory.php';
require_login($pdo);

$ivantiFile = diff_inventory_latest_csv(APP_ROOT . '/ivanti-import-csv', 'ivanti');
$satelliteFile = diff_inventory_latest_csv(APP_ROOT . '/satellite-import-csv', 'satellite');
$error = '';
$ivanti = ['rows' => [], 'duplicates' => 0, 'invalid' => 0, 'has_scan_date' => false];
$satellite = [];
$satelliteSnapshotDate = null;
try {
    if ($ivantiFile === null) throw new RuntimeException('No Ivanti CSV available in ivanti-import-csv.');
    if ($satelliteFile === null) throw new RuntimeException('No Satellite CSV available in satellite-import-csv.');
    $ivanti = diff_inventory_read_ivanti($ivantiFile);
    if (!$ivanti['has_scan_date']) {
        $extractionDate = diff_inventory_date_from_filename($ivantiFile);
        if ($extractionDate) {
            foreach ($ivanti['rows'] as &$ivantiRow) $ivantiRow['scan_date'] = $extractionDate->format('d/m/Y');
            unset($ivantiRow);
        }
    }
    $satellite = diff_inventory_read_satellite($satelliteFile);
    $satelliteSnapshotDate = diff_inventory_date_from_filename($satelliteFile);
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

$keys = array_unique(array_merge(array_keys($ivanti['rows']), array_keys($satellite)));
sort($keys, SORT_NATURAL | SORT_FLAG_CASE);
$familyOrder = ['Red Hat Enterprise Linux', 'Oracle Linux', 'SUSE Linux', 'Ubuntu', 'CentOS', 'Retired', 'Unknown'];
$groups = array_fill_keys($familyOrder, []);
$matched = $ivantiOnly = $satelliteOnly = 0;
$rhelAnomalies = [];
foreach ($keys as $key) {
    $iv = $ivanti['rows'][$key] ?? null;
    $sat = $satellite[$key] ?? null;
    if ($iv && $sat) $matched++;
    elseif ($iv) $ivantiOnly++;
    else $satelliteOnly++;
    $classification = diff_inventory_os((string) (($iv['os'] ?? null) ?: ($sat['os'] ?? '')));
    $groups[$classification['family']][] = ['ivanti' => $iv, 'satellite' => $sat, 'version' => $classification['version']];
    $anomaly = diff_inventory_rhel_anomaly($iv, $sat, $satelliteSnapshotDate);
    if ($anomaly) $rhelAnomalies[] = ['ivanti' => $iv, 'satellite' => $sat] + $anomaly;
}
$colors = [
    'Red Hat Enterprise Linux' => '#e74c3c', 'Oracle Linux' => '#c74634', 'SUSE Linux' => '#30ba78',
    'Ubuntu' => '#e95420', 'CentOS' => '#9b59b6', 'Retired' => '#7f8c8d', 'Unknown' => '#95a5a6',
];
$anomalyReasonOrder = [
    'Satellite check-in older than 30 days',
    'Ivanti only',
    'Satellite only',
    'Retired in Ivanti but still present in Satellite',
];
$anomalyReasonCounts = array_fill_keys($anomalyReasonOrder, 0);
foreach ($rhelAnomalies as $anomaly) {
    foreach ($anomaly['reasons'] as $reason) {
        if (array_key_exists($reason, $anomalyReasonCounts)) $anomalyReasonCounts[$reason]++;
    }
}
?>
<!doctype html>
<html lang="it"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Diff Inventory View - MoonCrater</title>
<link rel="icon" type="image/png" sizes="any" href="assets/img/favicon-mooncrater-130.png">
<link rel="stylesheet" href="assets/css/style.css">
<style>
body{background:#1a252f;color:#e5e8e8;font-family:Segoe UI,Tahoma,sans-serif;margin:0;padding:20px}.container{max-width:1900px;margin:auto}.header{display:flex;justify-content:space-between;gap:20px;align-items:center;margin-bottom:18px}.header h1{margin:0 0 5px}.back{color:#fff;text-decoration:none;background:#34495e;border-radius:5px;padding:9px 14px;font-weight:700}.source,.notice,.summary-card,.os-card,.anomaly-card{background:rgba(255,255,255,.035);border:1px solid rgba(255,255,255,.08);border-radius:8px}.source,.notice{padding:12px 15px;margin-bottom:14px}.notice{border-color:#b9770e;background:rgba(185,119,14,.16);color:#f8c471}.error{border-color:#922b21;background:rgba(146,43,33,.22);color:#f5b7b1}.summary{display:grid;grid-template-columns:repeat(5,minmax(130px,1fr));gap:12px;margin-bottom:18px}.summary-card{padding:14px}.summary-card span{display:block;color:#85929e;text-transform:uppercase;font-size:10px;font-weight:700;letter-spacing:.5px}.summary-card strong{font-size:28px}.os-grid{display:grid;grid-template-columns:1fr;gap:18px}.os-card{border-top:3px solid var(--accent);overflow:hidden}.os-head{padding:14px 16px;display:flex;justify-content:space-between;align-items:flex-start;gap:15px;flex-wrap:wrap}.os-head h2{margin:0;color:var(--accent)}.badges{display:flex;gap:7px;flex-wrap:wrap}.badge{background:rgba(0,0,0,.22);border:1px solid rgba(255,255,255,.09);border-radius:12px;padding:4px 9px;font-size:11px}.table-wrap{overflow:auto;max-height:520px}table{border-collapse:collapse;width:100%;font-size:12px}th,td{padding:9px 10px;border-top:1px solid rgba(255,255,255,.07);white-space:nowrap;text-align:left}thead th{position:sticky;top:0;background:#243442;color:#abb2b9;z-index:2}.group-head{text-align:center;color:#fff;background:#2c3e50}.sort-button{appearance:none;border:0;background:transparent;color:inherit;font:inherit;font-weight:700;padding:0;cursor:pointer}.sort-button:hover,.sort-button:focus{color:#fff}.sort-arrow{display:inline-block;width:12px;color:#5dade2}.missing{color:#7f8c8d}.iv-only{box-shadow:inset 4px 0 #f39c12}.sat-only{box-shadow:inset 4px 0 #3498db}.stale-checkin td{background:rgba(255,255,0,.20);border-top-color:rgba(255,255,0,.52)}.stale-checkin td:last-child{color:#ffff66;font-weight:800}.satellite-head,table th:nth-child(4),table td:nth-child(4){border-left:3px solid rgba(93,173,226,.8)}.match-label{display:block;font-size:9px;text-transform:uppercase;color:#7f8c8d;margin-top:2px}.muted{color:#95a5a6;font-size:12px}.anomaly-card{margin-top:24px;border-top:3px solid #ffff00;overflow:hidden}.anomaly-head{display:flex;justify-content:space-between;align-items:center;gap:15px;flex-wrap:wrap;padding:16px}.anomaly-head h2{margin:0;color:#ffff66}.actions{display:flex;gap:8px;flex-wrap:wrap}.action-button{border:1px solid rgba(255,255,255,.16);border-radius:5px;background:#34495e;color:#fff;padding:8px 12px;font-weight:700;cursor:pointer}.action-button.primary{background:#2471a3}.copy-status{color:#58d68d;font-size:12px}.anomaly-card .table-wrap{max-height:none}.anomaly-card td{white-space:normal;vertical-align:top}.anomaly-reason{color:#ffff66;font-weight:700}@media(max-width:900px){.summary{grid-template-columns:repeat(2,1fr)}}
</style><style>.anomaly-card table th:nth-child(4),.anomaly-card table td:nth-child(4){border-left:0}</style></head><body><main class="container">
<header class="header"><div><h1>Diff Inventory View</h1><div class="muted">Confronto alfabetico tra inventario Ivanti e snapshot Satellite</div></div><a class="back" href="index.php">Torna al portale</a></header>
<?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php else: ?>
<div class="source"><strong>Sorgenti:</strong> Ivanti <?= h(basename((string) $ivantiFile)) ?> · Satellite <?= h(basename((string) $satelliteFile)) ?><span style="float:right;color:#ffff66">■ Last Check-in oltre 30 giorni</span></div>
<?php if (!$ivanti['has_scan_date']): ?><div class="notice"><strong>Scan Date non presente nel CSV:</strong> per tutti gli host viene usata la data di estrazione <?= h(diff_inventory_date_from_filename((string) $ivantiFile)?->format('d/m/Y') ?? 'N/D') ?>, ricavata dal nome del file.</div><?php endif; ?>
<?php if ($ivanti['duplicates'] || $ivanti['invalid']): ?><div class="notice"><?= (int) $ivanti['duplicates'] ?> hostname duplicati consolidati; <?= (int) $ivanti['invalid'] ?> righe non valide ignorate.</div><?php endif; ?>
<section class="summary">
<?php foreach ([['Ivanti',count($ivanti['rows'])],['Satellite',count($satellite)],['Matched',$matched],['Ivanti only',$ivantiOnly],['Satellite only',$satelliteOnly]] as [$label,$value]): ?>
<div class="summary-card"><span><?= h($label) ?></span><strong><?= (int) $value ?></strong></div><?php endforeach; ?>
</section>
<section class="os-grid">
<?php foreach ($familyOrder as $family): $rows = $groups[$family]; if (!$rows) continue; $versions=[]; $familyIvantiTotal=0; $familySatelliteTotal=0; $familyCheckinAnomalies=0; foreach($rows as $row){$v=$row['version'];$versions[$v]=($versions[$v]??0)+1;if($row['ivanti'])$familyIvantiTotal++;if($row['satellite'])$familySatelliteTotal++;if($row['satellite']&&$satelliteSnapshotDate&&diff_inventory_checkin_is_stale($row['satellite']['last_checkin'],$satelliteSnapshotDate))$familyCheckinAnomalies++;} uksort($versions,'version_compare'); ?>
<article class="os-card" style="--accent:<?= h($colors[$family]) ?>"><div class="os-head"><div><h2><?= h($family) ?></h2><div class="muted">Total Ivanti Hosts: <strong><?= $familyIvantiTotal ?></strong> &nbsp;·&nbsp; Total Satellite Hosts: <strong><?= $familySatelliteTotal ?></strong> &nbsp;·&nbsp; <span style="color:#ffff66">Check-in Anomalies &gt;30d: <strong><?= $familyCheckinAnomalies ?></strong></span></div></div><div class="badges"><?php foreach($versions as $version=>$count): ?><span class="badge"><?= h($version) ?>: <strong><?= $count ?></strong></span><?php endforeach; ?></div></div>
<div class="table-wrap"><table class="sortable-table"><thead><tr><th class="group-head" colspan="3">IVANTI</th><th class="group-head satellite-head" colspan="5">SATELLITE</th></tr><tr><th><button class="sort-button" type="button" data-column="0">Hostname <span class="sort-arrow">↕</span></button></th><th><button class="sort-button" type="button" data-column="1">OS <span class="sort-arrow">↕</span></button></th><th>Scan Date</th><th><button class="sort-button" type="button" data-column="3">Hostname <span class="sort-arrow">↕</span></button></th><th><button class="sort-button" type="button" data-column="4">OS <span class="sort-arrow">↕</span></button></th><th>CV / ENV</th><th>Location</th><th>Last Check-in</th></tr></thead><tbody>
<?php foreach($rows as $row): $iv=$row['ivanti'];$sat=$row['satellite'];$class=$iv&&$sat?'':($iv?'iv-only':'sat-only');if($sat&&$satelliteSnapshotDate&&diff_inventory_checkin_is_stale($sat['last_checkin'],$satelliteSnapshotDate))$class=trim($class.' stale-checkin'); ?>
<tr class="<?= $class ?>"><td data-sort-value="<?= h($iv['hostname']??'') ?>" class="<?= $iv?'':'missing' ?>"><?= h($iv['hostname']??'N/D') ?><?php if($iv&&!$sat):?><small class="match-label">Ivanti only</small><?php endif;?></td><td data-sort-value="<?= h($iv['os']??'') ?>" class="<?= $iv?'':'missing' ?>"><?= h($iv['os']??'N/D') ?></td><td class="<?= $iv?'':'missing' ?>"><?= h($iv['scan_date']??'N/D') ?></td><td data-sort-value="<?= h($sat['hostname']??'') ?>" class="<?= $sat?'':'missing' ?>"><?= h($sat['hostname']??'N/D') ?><?php if($sat&&!$iv):?><small class="match-label">Satellite only</small><?php endif;?></td><td data-sort-value="<?= h($sat['os']??'') ?>" class="<?= $sat?'':'missing' ?>"><?= h($sat['os']??'N/D') ?></td><td class="<?= $sat?'':'missing' ?>"><?= h($sat['cv_env']??'N/D') ?></td><td class="<?= $sat?'':'missing' ?>"><?= h($sat['location']??'N/D') ?></td><td class="<?= $sat?'':'missing' ?>"><?= h($sat['last_checkin']??'N/D') ?></td></tr>
<?php endforeach; ?></tbody></table></div></article>
<?php endforeach; ?>
</section>
<section class="anomaly-card">
<div class="anomaly-head"><div><h2>RHEL Anomalies for Verification</h2><div class="muted"><strong><?= count($rhelAnomalies) ?></strong> host da verificare per spegnimento, dismissione, pulizia inventario o agent Satellite non funzionante</div><div class="badges" style="margin-top:9px"><?php foreach($anomalyReasonCounts as $reason=>$count): ?><span class="badge"><?= h($reason) ?>: <strong><?= $count ?></strong></span><?php endforeach; ?></div></div><div class="actions"><span id="anomaly-copy-status" class="copy-status" role="status"></span><button type="button" class="action-button primary" onclick="copyAnomalyTable()">Copy for email</button><button type="button" class="action-button" onclick="exportAnomalyExcel()">Export Excel</button><button type="button" class="action-button" onclick="exportAnomalyCsv()">Export CSV</button></div></div>
<div class="table-wrap"><table id="rhel-anomaly-table" class="sortable-table" data-renumber="true"><thead><tr><th>#</th><th>Hostname</th><th><button class="sort-button" type="button" data-column="2">Anomaly <span class="sort-arrow">↕</span></button></th><th>Ivanti OS</th><th>Ivanti Scan Date</th><th>Satellite OS</th><th>CV / ENV</th><th>Location</th><th>Last Check-in</th><th>Recommended Check</th></tr></thead><tbody>
<?php $anomalyNumber=1; foreach($rhelAnomalies as $anomaly): $iv=$anomaly['ivanti'];$sat=$anomaly['satellite'];$anomalyText=implode('; ', $anomaly['reasons']); ?>
<tr><td><?= $anomalyNumber++ ?></td><td><?= h($anomaly['hostname']) ?></td><td data-sort-value="<?= h($anomalyText) ?>" class="anomaly-reason"><?= h($anomalyText) ?></td><td><?= h($iv['os']??'N/D') ?></td><td><?= h($iv['scan_date']??'N/D') ?></td><td><?= h($sat['os']??'N/D') ?></td><td><?= h($sat['cv_env']??'N/D') ?></td><td><?= h($sat['location']??'N/D') ?></td><td><?= h($sat['last_checkin']??'N/D') ?></td><td><?= h(implode('; ', $anomaly['actions'])) ?></td></tr>
<?php endforeach; ?>
<?php if(!$rhelAnomalies): ?><tr><td colspan="10" class="muted">No RHEL anomalies found.</td></tr><?php endif; ?>
</tbody></table></div>
</section>
<?php endif; ?>
<script>
document.querySelectorAll('.sortable-table').forEach((table) => {
    table.querySelectorAll('.sort-button').forEach((button) => {
        button.addEventListener('click', () => {
            const column = Number(button.dataset.column);
            const ascending = button.dataset.direction !== 'asc';
            const body = table.tBodies[0];
            const rows = Array.from(body.rows);
            rows.sort((left, right) => {
                const leftValue = (left.cells[column].dataset.sortValue || '').trim();
                const rightValue = (right.cells[column].dataset.sortValue || '').trim();
                if (leftValue === '' && rightValue !== '') return 1;
                if (rightValue === '' && leftValue !== '') return -1;
                const order = leftValue.localeCompare(rightValue, undefined, {numeric: true, sensitivity: 'base'});
                return ascending ? order : -order;
            });
            rows.forEach((row) => body.appendChild(row));
            if (table.dataset.renumber === 'true') {
                Array.from(body.rows).forEach((row, index) => { row.cells[0].textContent = String(index + 1); });
            }
            table.querySelectorAll('.sort-button').forEach((other) => {
                other.dataset.direction = '';
                other.querySelector('.sort-arrow').textContent = '↕';
            });
            button.dataset.direction = ascending ? 'asc' : 'desc';
            button.querySelector('.sort-arrow').textContent = ascending ? '↑' : '↓';
        });
    });
});

function anomalyTableRows() {
    const table = document.getElementById('rhel-anomaly-table');
    return Array.from(table.rows).map((row) => Array.from(row.cells).map((cell) => cell.innerText.trim()));
}

async function copyAnomalyTable() {
    const text = anomalyTableRows().map((row) => row.join('\t')).join('\n');
    const status = document.getElementById('anomaly-copy-status');
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
        } else {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            if (!document.execCommand('copy')) throw new Error('Copy command failed');
            textarea.remove();
        }
        status.textContent = 'Copied';
    } catch (error) {
        status.textContent = 'Copy failed: select the table manually';
    }
    window.setTimeout(() => { status.textContent = ''; }, 4000);
}

function exportAnomalyCsv() {
    const spreadsheetSafe = (value) => /^[=+\-@]/.test(value) ? "'" + value : value;
    const csv = anomalyTableRows().map((row) => row.map((value) => '"' + spreadsheetSafe(value).replace(/"/g, '""') + '"').join(',')).join('\r\n');
    const blob = new Blob(['\uFEFF' + csv], {type: 'text/csv;charset=utf-8'});
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'mooncrater-rhel-anomalies.csv';
    document.body.appendChild(link);
    link.click();
    URL.revokeObjectURL(link.href);
    link.remove();
}

function excelXmlEscape(value) {
    return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&apos;');
}

function excelCell(value, style = 'Cell') {
    const safeValue = /^[=+\-@]/.test(value) ? "'" + value : value;
    return '<Cell ss:StyleID="' + style + '"><Data ss:Type="String">' + excelXmlEscape(safeValue) + '</Data></Cell>';
}

function exportAnomalyExcel() {
    const rows = anomalyTableRows();
    const summary = <?= json_encode(array_merge(['Total hosts to verify' => count($rhelAnomalies)], $anomalyReasonCounts), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const widths = [42, 145, 250, 210, 105, 210, 170, 145, 145, 285];
    const columns = widths.map((width) => '<Column ss:AutoFitWidth="0" ss:Width="' + width + '"/>').join('');
    const summaryRows = Object.entries(summary).map(([label, count], index) =>
        '<Row>' + excelCell(label, index === 0 ? 'SummaryTotalLabel' : 'SummaryLabel') +
        '<Cell ss:StyleID="' + (index === 0 ? 'SummaryTotalValue' : 'SummaryValue') + '"><Data ss:Type="Number">' + count + '</Data></Cell></Row>'
    ).join('');
    const detailRows = rows.map((row, rowIndex) => {
        if (rowIndex === 0) return '<Row ss:AutoFitHeight="1">' + row.map((value) => excelCell(value, 'Header')).join('') + '</Row>';
        const anomaly = row[2] || '';
        let style = 'Cell';
        if (anomaly.includes('older than 30 days')) style = 'Stale';
        else if (anomaly.includes('Ivanti only')) style = 'IvantiOnly';
        else if (anomaly.includes('Satellite only')) style = 'SatelliteOnly';
        else if (anomaly.includes('Retired')) style = 'Retired';
        return '<Row ss:AutoFitHeight="1">' + row.map((value, columnIndex) => excelCell(value, columnIndex === 2 ? style : 'Cell')).join('') + '</Row>';
    }).join('');
    const xml = '<?xml version="1.0"?><?mso-application progid="Excel.Sheet"?>' +
        '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' +
        '<DocumentProperties xmlns="urn:schemas-microsoft-com:office:office"><Title>MoonCrater RHEL Anomalies</Title><Author>MoonCrater</Author><Created>' + new Date().toISOString() + '</Created></DocumentProperties>' +
        '<Styles>' +
        '<Style ss:ID="Default" ss:Name="Normal"><Alignment ss:Vertical="Top" ss:WrapText="1"/><Font ss:FontName="Calibri" ss:Size="11"/><Borders/><Interior/><NumberFormat/><Protection/></Style>' +
        '<Style ss:ID="Title"><Font ss:FontName="Calibri" ss:Size="18" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#1F4E78" ss:Pattern="Solid"/><Alignment ss:Vertical="Center"/></Style>' +
        '<Style ss:ID="Header"><Font ss:FontName="Calibri" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#2F75B5" ss:Pattern="Solid"/><Alignment ss:Vertical="Center" ss:WrapText="1"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D9EAF7"/></Borders></Style>' +
        '<Style ss:ID="Cell"><Alignment ss:Vertical="Top" ss:WrapText="1"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D9E2F3"/></Borders></Style>' +
        '<Style ss:ID="SummaryLabel"><Font ss:Bold="1"/><Interior ss:Color="#D9EAF7" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#FFFFFF"/></Borders></Style>' +
        '<Style ss:ID="SummaryValue"><Font ss:Bold="1" ss:Color="#1F4E78"/><Interior ss:Color="#EAF2F8" ss:Pattern="Solid"/><Alignment ss:Horizontal="Center"/></Style>' +
        '<Style ss:ID="SummaryTotalLabel" ss:Parent="SummaryLabel"><Font ss:Size="13" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#1F4E78" ss:Pattern="Solid"/></Style>' +
        '<Style ss:ID="SummaryTotalValue" ss:Parent="SummaryValue"><Font ss:Size="13" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#1F4E78" ss:Pattern="Solid"/><Alignment ss:Horizontal="Center"/></Style>' +
        '<Style ss:ID="Stale" ss:Parent="Cell"><Font ss:Bold="1"/><Interior ss:Color="#FFF2CC" ss:Pattern="Solid"/></Style>' +
        '<Style ss:ID="IvantiOnly" ss:Parent="Cell"><Font ss:Bold="1" ss:Color="#9C5700"/><Interior ss:Color="#FCE4D6" ss:Pattern="Solid"/></Style>' +
        '<Style ss:ID="SatelliteOnly" ss:Parent="Cell"><Font ss:Bold="1" ss:Color="#1F4E78"/><Interior ss:Color="#DDEBF7" ss:Pattern="Solid"/></Style>' +
        '<Style ss:ID="Retired" ss:Parent="Cell"><Font ss:Bold="1" ss:Color="#9C0006"/><Interior ss:Color="#FFC7CE" ss:Pattern="Solid"/></Style>' +
        '</Styles>' +
        '<Worksheet ss:Name="Summary"><Table><Column ss:Width="310"/><Column ss:Width="100"/><Row ss:Height="32"><Cell ss:StyleID="Title" ss:MergeAcross="1"><Data ss:Type="String">MoonCrater - RHEL Anomaly Summary</Data></Cell></Row><Row ss:Height="8"/>' + summaryRows + '</Table><WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel"><Selected/><FreezePanes/><FrozenNoSplit/><SplitHorizontal>2</SplitHorizontal><TopRowBottomPane>2</TopRowBottomPane><ProtectObjects>False</ProtectObjects><ProtectScenarios>False</ProtectScenarios></WorksheetOptions></Worksheet>' +
        '<Worksheet ss:Name="RHEL Anomalies"><Table>' + columns + detailRows + '</Table><AutoFilter x:Range="R1C1:R' + Math.max(rows.length, 1) + 'C10" xmlns="urn:schemas-microsoft-com:office:excel"/><WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel"><FreezePanes/><FrozenNoSplit/><SplitHorizontal>1</SplitHorizontal><TopRowBottomPane>1</TopRowBottomPane><ProtectObjects>False</ProtectObjects><ProtectScenarios>False</ProtectScenarios></WorksheetOptions></Worksheet>' +
        '</Workbook>';
    const blob = new Blob(['\uFEFF' + xml], {type: 'application/vnd.ms-excel;charset=utf-8'});
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'mooncrater-rhel-anomalies-' + new Date().toISOString().slice(0, 10) + '.xml';
    document.body.appendChild(link);
    link.click();
    URL.revokeObjectURL(link.href);
    link.remove();
}
</script>
</main></body></html>
