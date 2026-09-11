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
foreach ($keys as $key) {
    $iv = $ivanti['rows'][$key] ?? null;
    $sat = $satellite[$key] ?? null;
    if ($iv && $sat) $matched++;
    elseif ($iv) $ivantiOnly++;
    else $satelliteOnly++;
    $classification = diff_inventory_os((string) (($iv['os'] ?? null) ?: ($sat['os'] ?? '')));
    $groups[$classification['family']][] = ['ivanti' => $iv, 'satellite' => $sat, 'version' => $classification['version']];
}
$colors = [
    'Red Hat Enterprise Linux' => '#e74c3c', 'Oracle Linux' => '#c74634', 'SUSE Linux' => '#30ba78',
    'Ubuntu' => '#e95420', 'CentOS' => '#9b59b6', 'Retired' => '#7f8c8d', 'Unknown' => '#95a5a6',
];
?>
<!doctype html>
<html lang="it"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Diff Inventory View - MoonCrater</title>
<link rel="icon" type="image/png" sizes="any" href="assets/img/favicon-mooncrater-130.png">
<link rel="stylesheet" href="assets/css/style.css">
<style>
body{background:#1a252f;color:#e5e8e8;font-family:Segoe UI,Tahoma,sans-serif;margin:0;padding:20px}.container{max-width:1900px;margin:auto}.header{display:flex;justify-content:space-between;gap:20px;align-items:center;margin-bottom:18px}.header h1{margin:0 0 5px}.back{color:#fff;text-decoration:none;background:#34495e;border-radius:5px;padding:9px 14px;font-weight:700}.source,.notice,.summary-card,.os-card{background:rgba(255,255,255,.035);border:1px solid rgba(255,255,255,.08);border-radius:8px}.source,.notice{padding:12px 15px;margin-bottom:14px}.notice{border-color:#b9770e;background:rgba(185,119,14,.16);color:#f8c471}.error{border-color:#922b21;background:rgba(146,43,33,.22);color:#f5b7b1}.summary{display:grid;grid-template-columns:repeat(5,minmax(130px,1fr));gap:12px;margin-bottom:18px}.summary-card{padding:14px}.summary-card span{display:block;color:#85929e;text-transform:uppercase;font-size:10px;font-weight:700;letter-spacing:.5px}.summary-card strong{font-size:28px}.os-grid{display:grid;grid-template-columns:1fr;gap:18px}.os-card{border-top:3px solid var(--accent);overflow:hidden}.os-head{padding:14px 16px;display:flex;justify-content:space-between;align-items:flex-start;gap:15px;flex-wrap:wrap}.os-head h2{margin:0;color:var(--accent)}.badges{display:flex;gap:7px;flex-wrap:wrap}.badge{background:rgba(0,0,0,.22);border:1px solid rgba(255,255,255,.09);border-radius:12px;padding:4px 9px;font-size:11px}.table-wrap{overflow:auto;max-height:520px}table{border-collapse:collapse;width:100%;font-size:12px}th,td{padding:9px 10px;border-top:1px solid rgba(255,255,255,.07);white-space:nowrap;text-align:left}thead th{position:sticky;top:0;background:#243442;color:#abb2b9;z-index:2}.group-head{text-align:center;color:#fff;background:#2c3e50}.sort-button{appearance:none;border:0;background:transparent;color:inherit;font:inherit;font-weight:700;padding:0;cursor:pointer}.sort-button:hover,.sort-button:focus{color:#fff}.sort-arrow{display:inline-block;width:12px;color:#5dade2}.missing{color:#7f8c8d}.iv-only{box-shadow:inset 4px 0 #f39c12}.sat-only{box-shadow:inset 4px 0 #3498db}.stale-checkin td{background:rgba(255,255,0,.20);border-top-color:rgba(255,255,0,.52)}.stale-checkin td:last-child{color:#ffff66;font-weight:800}.satellite-head,table th:nth-child(4),table td:nth-child(4){border-left:3px solid rgba(93,173,226,.8)}.match-label{display:block;font-size:9px;text-transform:uppercase;color:#7f8c8d;margin-top:2px}.muted{color:#95a5a6;font-size:12px}@media(max-width:900px){.summary{grid-template-columns:repeat(2,1fr)}}
</style></head><body><main class="container">
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
</section><?php endif; ?>
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
            table.querySelectorAll('.sort-button').forEach((other) => {
                other.dataset.direction = '';
                other.querySelector('.sort-arrow').textContent = '↕';
            });
            button.dataset.direction = ascending ? 'asc' : 'desc';
            button.querySelector('.sort-arrow').textContent = ascending ? '↑' : '↓';
        });
    });
});
</script>
</main></body></html>
