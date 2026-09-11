<?php
require __DIR__ . '/../app/bootstrap.php';
$current_user = require_login($pdo);
$is_admin = (($current_user["role"] ?? "user") === "admin");
require_once __DIR__ . '/../app/Domain/inventory.php';


// Forziamo la timezone corretta
date_default_timezone_set('Europe/Rome');

$last_ivanti_import = "Never / No Data";

try {
    // Interroghiamo il DB convertendo la stringa scan_date nel formato corretto
    $stmt_ivanti = $pdo->query("SELECT MAX(to_timestamp(scan_date, 'DD/MM/YYYY HH24:MI')) FROM inventory_ivanti");
    $db_date_ivanti = $stmt_ivanti->fetchColumn();

    if ($db_date_ivanti) {
        // Formattiamo la data nel formato internazionale YYYY-MM-DD
        $last_ivanti_import = date("Y-m-d", strtotime($db_date_ivanti));
    }
} catch (Exception $e) {
    // Se la tabella è vuota o la colonna non corrisponde, gestisce l'errore senza rompere la pagina
    $last_ivanti_import = "Error / No Data";
}


// 2. Red Hat Satellite Inventory Logic (Robust against "N/A" values)
$last_satellite_import = "Never / No Data";
try {
    // We explicitly exclude 'N/A' and empty strings to prevent conversion syntax errors
    $stmt_satellite = $pdo->query("
        SELECT MAX(last_checkin::timestamp)
        FROM inventory_satellite
        WHERE last_checkin IS NOT NULL
          AND last_checkin != 'N/A'
          AND last_checkin != ''
    ");
    $db_date_sat = $stmt_satellite->fetchColumn();

    if ($db_date_sat) {
        $last_satellite_import = date("Y-m-d", strtotime($db_date_sat));
    }
} catch (Exception $e) {
    // Falls back smoothly in case of schema mismatches
    $last_satellite_import = "Error / No Data";
}













$filter_missing = $_GET['missing'] ?? 'all';
$filter_os = $_GET['os'] ?? 'all';
$filter_search = $_GET['search'] ?? '';

// --- 1. CARICAMENTO DATI ---
$ivanti_rows = $pdo->query("SELECT hostname, os, ip, scan_date, status FROM inventory_ivanti ORDER BY hostname")->fetchAll(PDO::FETCH_ASSOC);
$ivanti_count = count($ivanti_rows);

$sat_stmt = $pdo->query("SELECT hostname, os, ip, kernel, content_view_environment, location, last_checkin, status, role FROM inventory_satellite");
$sat_lookup = [];
$satellite_count = 0;


// --- CARICAMENTO NOTE ---
$notes_stmt = $pdo->query("SELECT hostname, notes, migration_date FROM server_notes");
$notes_lookup = [];
foreach ($notes_stmt->fetchAll(PDO::FETCH_ASSOC) as $note_row) {
    $notes_lookup[normalize_hostname($note_row['hostname'])] = $note_row;
}

// --- CARICAMENTO INFRASTRUCTURE HOSTS da inventory_satellite.role ---
// Satellite e Capsule identificati dalla colonna role — non serve tabella separata
$infra_lookup = [];
try {
    $infra_stmt = $pdo->query("
        SELECT hostname, role, os, ip, kernel, location, last_checkin, content_view_environment
        FROM inventory_satellite
        WHERE role IN ('satellite', 'capsule')
        ORDER BY role, hostname
    ");
    foreach ($infra_stmt->fetchAll(PDO::FETCH_ASSOC) as $infra) {
        $norm_infra = normalize_hostname($infra['hostname']);
        $infra_lookup[$norm_infra] = $infra;
        $infra_lookup[strtolower(trim($infra['hostname']))] = $infra;
    }
} catch (Exception $e) {
    $infra_lookup = [];
}

// --- CARICAMENTO ESCLUSIONI da inventory_ivanti.status ---
// Le esclusioni vivono su inventory_ivanti (sopravvivono ai reimport di Satellite)
$exclusions_lookup = [];
try {
    $excl_stmt = $pdo->query("
        SELECT LOWER(TRIM(hostname)) as hostname
        FROM inventory_ivanti
        WHERE status IN ('excluded', 'decommissioned')
    ");
    foreach ($excl_stmt->fetchAll(PDO::FETCH_COLUMN) as $excl_hostname) {
        $exclusions_lookup[$excl_hostname] = true;
    }
} catch (Exception $e) {
    $exclusions_lookup = [];
}

while ($s = $sat_stmt->fetch(PDO::FETCH_ASSOC)) {
    $satellite_count++;
    $norm = normalize_hostname($s['hostname']);
    $sat_lookup[$norm] = $s;
}

// --- 4. CONTEGGIO KERNEL CON ORDINAMENTO LOGICO VERSIONATO ---
$kernel_stats_query = $pdo->query("
    SELECT os, kernel, COUNT(*) as total
    FROM inventory_satellite
    WHERE status = 'active' AND kernel IS NOT NULL AND kernel <> 'N/A'
    GROUP BY os, kernel
    ORDER BY
        -- 1. Ordina per Major OS (10, 9, 8, 7)
        (string_to_array(regexp_replace(os, '[^0-9.]', '', 'g'), '.')::int[])[1] DESC,
        -- 2. Ordina per Minor OS (gestisce correttamente 10 > 9)
        string_to_array(regexp_replace(os, '[^0-9.]', '', 'g'), '.')::int[] DESC,
        -- 3. Ordina per Kernel (stessa logica)
        string_to_array(regexp_replace(kernel, '[^0-9.]', '', 'g'), '.')::int[] DESC
");
$kernel_stats = $kernel_stats_query->fetchAll(PDO::FETCH_ASSOC);



// --- 2. ELABORAZIONE E MATCHING ---
$merged_rows = [];
$missing_count = 0;
$matched_sat_keys = [];

// Inizializziamo i contatori a zero
$active_ivanti_count = 0;
$active_satellite_count = 0;
$ivanti_rhel7 = $ivanti_rhel8 = $ivanti_rhel9 = $ivanti_rhel10 = $ivanti_unknown = 0;
$sat_rhel7 = $sat_rhel8 = $sat_rhel9 = $sat_rhel10 = $sat_unknown = 0;

foreach ($ivanti_rows as $row) {
    $norm_ivanti = normalize_hostname($row['hostname']);
    $sat_record = $sat_lookup[$norm_ivanti] ?? null;
    $sat = ($sat_record && ($sat_record['status'] ?? 'active') !== 'missing') ? $sat_record : null;
    $iv_status = $row['status'] ?? 'active';

    // Salta infrastructure hosts (Satellite + Capsule) — gestiti separatamente
    if (isset($infra_lookup[$norm_ivanti]) || isset($infra_lookup[strtolower(trim($row['hostname']))])) {
        // Segniamo il match per evitare che appaiano nella satellite-only
        if ($sat_record) $matched_sat_keys[$norm_ivanti] = true;
        $merged_rows[] = ['ivanti' => $row, 'satellite' => $sat_record, 'is_infra' => true];
        continue;
    }

    // L'esclusione viene da inventory_ivanti.status
    $host_key         = strtolower(trim($row['hostname']));
    $host_key_norm    = explode('.', $host_key)[0];
    $is_excluded_host = isset($exclusions_lookup[$host_key])
                     || isset($exclusions_lookup[$host_key_norm]);

    // Solo gli host non esclusi e attivi entrano nei conteggi
    if ($iv_status === 'active' && !$is_excluded_host) {
        $active_ivanti_count++;

        $iv_major = detect_os_major($row['os'] ?? '');
        if ($iv_major === '7')      $ivanti_rhel7++;
        elseif ($iv_major === '8')  $ivanti_rhel8++;
        elseif ($iv_major === '9')  $ivanti_rhel9++;
        elseif ($iv_major === '10') $ivanti_rhel10++;
        else                        $ivanti_unknown++;
    }

    if ($sat) {
        // Presente nel CSV corrente: active oppure unhealthy.
        $matched_sat_keys[$norm_ivanti] = true;
    } elseif ($iv_status === 'active' && !$is_excluded_host) {
        $missing_count++;
    }

    $merged_rows[] = ['ivanti' => $row, 'satellite' => $sat, 'is_infra' => false];
}

// --- 3. CALCOLO SATELLITE ONLY E TOTALE SATELLITE ATTIVO ---
$satellite_only_count = 0;
$active_satellite_count = 0;

foreach ($sat_lookup as $key => $sat_data) {
    $s_status    = $sat_data['status'] ?? 'active';
    $s_host_key  = strtolower(trim($sat_data['hostname'] ?? ''));
    $s_excluded  = isset($exclusions_lookup[$s_host_key]) || isset($exclusions_lookup[$key]);

    // Salta infrastructure hosts
    if (isset($infra_lookup[$key]) || isset($infra_lookup[$s_host_key])) continue;

    // Solo gli host attivi e non esclusi entrano nei conteggi
    if ($s_status === 'active' && !$s_excluded) {
        $active_satellite_count++;

        if (!isset($matched_sat_keys[$key])) {
            $satellite_only_count++;
        }

        $sat_major = detect_os_major($sat_data['os'] ?? '');
        if ($sat_major === '7')      $sat_rhel7++;
        elseif ($sat_major === '8')  $sat_rhel8++;
        elseif ($sat_major === '9')  $sat_rhel9++;
        elseif ($sat_major === '10') $sat_rhel10++;
        else                         $sat_unknown++;
    }
}



// 1. Recuperiamo il filtro location inviato via GET (se non c'è, impostiamo 'all')
$filter_location = $_GET['location'] ?? 'all';

// 2. Query dinamica per estrarre tutte le location uniche presenti nel DB (escludendo N/A e vuoti)
$locations_list = [];
try {
    $stmt_loc = $pdo->query("SELECT DISTINCT location FROM inventory_satellite WHERE location IS NOT NULL AND location != 'N/A' AND location != '' ORDER BY location ASC");
    $locations_list = $stmt_loc->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    // Fallback sicuro se la tabella fosse vuota
    $locations_list = [];
}



// Soglia operativa centralizzata per il last check-in Satellite.
$satellite_checkin_limit = date('Y-m-d H:i:s', strtotime('-' . SATELLITE_CHECKIN_MAX_AGE_DAYS . ' days'));

$outdated_satellite_count = 0;
$total_satellite_count = 0;

try {
    // Contiamo gli host unhealthy: presenti in Satellite con check-in scaduto/mancante
    // Escludiamo i record fantasma (os vuoto) e gli host già esclusi da Ivanti
    $stmt_out = $pdo->prepare("
        SELECT COUNT(*)
        FROM inventory_satellite s
        INNER JOIN inventory_ivanti i
            ON LOWER(SPLIT_PART(s.hostname, '.', 1)) = LOWER(SPLIT_PART(i.hostname, '.', 1))
        WHERE i.status = 'active'
          AND (s.os IS NOT NULL AND s.os != '' AND s.os != 'N/A')
          AND (
                s.last_checkin IS NULL
             OR s.last_checkin = 'N/A'
             OR s.last_checkin = ''
             OR (
                  s.last_checkin != 'N/A'
                  AND s.last_checkin != ''
                  AND s.last_checkin::timestamp < :limit_date
                )
          )
    ");
    $stmt_out->execute([':limit_date' => $satellite_checkin_limit]);
    $outdated_satellite_count = $stmt_out->fetchColumn();

    // Totale host censiti su Satellite (esclude fantasmi)
    $total_satellite_count = $pdo->query("
        SELECT COUNT(*) FROM inventory_satellite
        WHERE os IS NOT NULL AND os != '' AND os != 'N/A'
    ")->fetchColumn();

} catch (Exception $e) {
    $outdated_satellite_count = 0;
}


// ... dopo la connessione al DB ($pdo) e gli altri conteggi esistenti ...

$total_agent_anomalies = 0;
try {
    // Conta gli host attivi che non hanno un IP valido valorizzato
    $stmt_anomalies = $pdo->prepare("
        SELECT COUNT(*)
        FROM inventory_satellite
        WHERE status = 'active'
          AND (
            ip IS NULL
            OR TRIM(ip) = ''
            OR TRIM(ip) = 'N/A'
            OR TRIM(ip) = 'N/D'
          )
    ");
    $stmt_anomalies->execute();
    $total_agent_anomalies = (int)$stmt_anomalies->fetchColumn();
} catch (Exception $e) {
    // Gestione errore opzionale, per ora stampiamo solo a schermo in caso di problemi
    $total_agent_anomalies = 0;
}



// Ricalcolo Coverage sui dati attivi
$coverage = ($active_ivanti_count > 0) ? round((($active_ivanti_count - $missing_count) / $active_ivanti_count) * 100, 1) : 0;

// --- STATISTICHE INFRASTRUCTURE HOSTS (Satellite + Capsule) ---
// Tutti i dati vengono da inventory_satellite (aggiornati ad ogni import CSV)
// Il campo role identifica satellite/capsule senza tabelle separate
$infra_stats = [];
try {
    $infra_stmt = $pdo->query("
        SELECT
            s.hostname, s.role, s.os, s.ip, s.kernel,
            s.location, s.last_checkin, s.content_view_environment,
            i.os        AS iv_os,
            i.ip        AS iv_ip,
            i.scan_date AS iv_scan_date,
            i.role      AS iv_role
        FROM inventory_satellite s
        LEFT JOIN inventory_ivanti i
            ON LOWER(SPLIT_PART(s.hostname, '.', 1)) = LOWER(SPLIT_PART(i.hostname, '.', 1))
        WHERE s.role IN ('satellite', 'capsule')
        ORDER BY CASE s.role WHEN 'satellite' THEN 0 ELSE 1 END, s.hostname
    ");
    foreach ($infra_stmt->fetchAll(PDO::FETCH_ASSOC) as $inf) {
        $infra_stats[] = [
            'hostname'     => $inf['hostname'],
            'role'         => $inf['role'],
            'os'           => $inf['os'] ?: ($inf['iv_os'] ?: 'N/A'),
            'ip'           => $inf['ip'] ?: ($inf['iv_ip'] ?: 'N/A'),
            'kernel'       => $inf['kernel'] ?: 'N/A',
            'location'     => $inf['location'] ?: 'N/A',
            'last_checkin' => $inf['last_checkin'] ?: 'N/D',
            'cv_env'       => $inf['content_view_environment'] ?: 'N/A',
            'iv_scan_date' => $inf['iv_scan_date'] ?: 'N/D',
        ];
    }
} catch (Exception $e) {
    $infra_stats = [];
}
// Calcolati sul loop merged_rows (stessa logica dei filtri PHP)
$satellite_checkin_limit_obj = (new DateTime())->modify('-' . SATELLITE_CHECKIN_MAX_AGE_DAYS . ' days');

$unified_healthy   = 0;
$unified_unhealthy = 0;
$satonly_healthy   = 0;
$satonly_unhealthy = 0;

foreach ($merged_rows as $m) {
    $iv  = $m['ivanti'];
    $sat = $m['satellite'];

    // Skippa infra hosts (Satellite/Capsule) — hanno contatori separati
    if (!empty($m['is_infra'])) continue;

    // Skippa esclusi e non-active
    $h_key      = strtolower(trim($iv['hostname'] ?? ''));
    $is_excl    = isset($exclusions_lookup[$h_key]) || isset($exclusions_lookup[normalize_hostname($iv['hostname'] ?? '')]);
    $iv_status  = $iv['status'] ?? 'active';
    if ($is_excl || $iv_status !== 'active') continue;

    // Solo host con match in Satellite (not missing)
    if (!$sat) continue;

    $lc = $sat['last_checkin'] ?? '';
    if (!empty($lc) && $lc !== 'N/A' && $lc !== 'N/D') {
        $lc_obj = DateTime::createFromFormat('Y-m-d', substr($lc, 0, 10));
        if ($lc_obj && $lc_obj >= $satellite_checkin_limit_obj) {
            $unified_healthy++;
        } else {
            $unified_unhealthy++;
        }
    } else {
        $unified_unhealthy++;
    }
}

// Satellite Only
foreach ($sat_lookup as $norm_name => $sat_data) {
    if (isset($matched_sat_keys[$norm_name])) continue; // già contato in unified

    $s_hkey   = strtolower(trim($sat_data['hostname'] ?? ''));
    $s_excl   = isset($exclusions_lookup[$s_hkey]);
    $s_status = $sat_data['status'] ?? 'active';
    if ($s_excl || !in_array($s_status, ['active', 'unhealthy'], true)) continue;

    // Salta infra hosts
    if (isset($infra_lookup[$norm_name]) || isset($infra_lookup[$s_hkey])) continue;

    // Escludi fantasmi
    if (empty($sat_data['os']) || $sat_data['os'] === 'N/A') continue;

    $lc = $sat_data['last_checkin'] ?? '';
    if (!empty($lc) && $lc !== 'N/A' && $lc !== 'N/D') {
        $lc_obj = DateTime::createFromFormat('Y-m-d', substr($lc, 0, 10));
        if ($lc_obj && $lc_obj >= $satellite_checkin_limit_obj) {
            $satonly_healthy++;
        } else {
            $satonly_unhealthy++;
        }
    } else {
        $satonly_unhealthy++;
    }
}

$total_healthy   = $unified_healthy   + $satonly_healthy;
$total_unhealthy = $unified_unhealthy + $satonly_unhealthy;





?>

<!DOCTYPE html>
<html>

<head>
    <title>MoonCrater - Infrastructure Control Plane Patching</title>
    <link rel="icon" type="image/png" sizes="any" href="assets/img/favicon-mooncrater-130.png">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        /* Host esclusi dai conteggi: grigio opaco, testo tenue, dati ancora leggibili */
        tr.excluded-row {
            opacity: 0.45;
            filter: grayscale(60%);
        }
        tr.excluded-row td {
            color: #7f8c8d !important;
        }
        tr.excluded-row:hover {
            opacity: 0.65;
        }
    </style>
    <script>window.CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;</script>
<?php if (!$is_admin): ?><style>.btn-exclude, [onclick^="infraEditStart"], form[action="backup_db.php"], #importForm, #ivantiImportForm { display:none !important; }.note-cell-style input, .note-cell-style textarea { pointer-events:none; opacity:.75; }</style><?php endif; ?>
</head>

<body>


<div class="container">



    <?php
    // --- CHANGELOG ---
    $changelog = [
        '1.39' => [
            'date' => '2026-09-11',
            'changes' => [
                'Aggiunta Diff Inventory View per confrontare gli snapshot CSV Ivanti e Satellite',
                'Inventario diff suddiviso per RHEL, Oracle Linux, SUSE, Ubuntu, CentOS, Retired e Unknown con contatori release',
                'Aggiunti totali Ivanti e Satellite, ordinamento colonne e conteggio anomalie per ogni riquadro',
                'Evidenziati in giallo fluorescente i Last Check-in Satellite oltre 30 giorni rispetto allo snapshot',
                'Usata automaticamente la data di estrazione nel filename quando il CSV Ivanti non contiene Scan Date',
                'Unified Inventory View e Satellite Only rese collassabili dalla barra del titolo e chiuse di default',
                'Ivanti Hostname visibile di default e valorizzato N/A nella vista Satellite Only',
                'Aggiornati export CSV e persistenza della directory locale ivanti-import-csv durante gli update',
            ],
        ],
        '1.38' => [
            'date' => '2026-09-08',
            'changes' => [
                'Creazione automatica dell account di sistema mooncrater-import durante installazione e aggiornamento RHEL 10',
                'Creazione automatica della home, della directory SSH protetta e della inbox Satellite con proprietario e permessi corretti',
                'Aggiunte indicazioni finali per installare l exporter sul Satellite e autorizzare la chiave pubblica generata',
                'Documentato il funzionamento oneshot del servizio e il controllo della pianificazione tramite timer systemd',
                'Riordinata la procedura completa MoonCrater, Satellite, autorizzazione SSH e test manuale',
            ],
        ],
        '1.37' => [
            'date' => '2026-09-08',
            'changes' => [
                'Aggiunta elaborazione automatica della cartella satellite-import-csv ogni cinque minuti',
                'Il CSV Satellite piu recente aggiorna inventario, diff e statistiche; i precedenti alimentano lo storico migrazioni',
                'Aggiunti timestamp data, ora e minuti ai nomi CSV e alla linea temporale Migration Trends',
                'Aggiunti import cronologico, rilevamento dei file gia elaborati, retry degli errori e lock contro esecuzioni simultanee',
                'Aggiunto trasferimento SCP atomico tramite file temporaneo per evitare import parziali',
                'Aggiunti account SSH dedicato, servizio e timer systemd per il server MoonCrater',
                'Aggiunto installer generico per Satellite con richiesta del server target, chiave SSH dedicata e cron giornaliero alle 02:00',
                'Aggiunti import storico da cartella, data snapshot ricavata dal filename e reset sicuro del grafico',
                'Preservata la cartella degli export Satellite durante gli aggiornamenti applicativi',
            ],
        ],
        '1.36' => [
            'date' => '2026-09-08',
            'changes' => [
                'Aggiunta migrazione automatica dei percorsi legacy verso /opt/mooncrater e /etc/mooncrater',
                'Aggiornati VirtualHost Apache, log e contesti SELinux durante la rinomina',
                'Aggiunto rollback automatico della migrazione filesystem in caso di errore',
            ],
        ],
        '1.35' => [
            'date' => '2026-09-08',
            'changes' => [
                'Corretto il nome del prodotto da MoonCreater a MoonCrater',
                'Rinominati logo, favicon, configurazione Apache e riferimenti del progetto',
                'Mantenuta compatibilita automatica con installazioni e configurazioni precedenti',
            ],
        ],
        '1.34' => [
            'date' => '2026-09-08',
            'changes' => [
                'Estesa da 3 a 30 giorni la soglia di validita del last check-in Satellite',
                'Allineati import corrente, storico, dashboard, filtri e pagine statistiche alla nuova soglia',
                'Centralizzata la soglia Satellite per evitare differenze tra le diverse viste',
            ],
        ],
        '1.33' => [
            'date' => '2026-09-08',
            'changes' => [
                'Aggiunto import Ivanti transazionale da dashboard e CLI',
                'Supportato il formato CSV Device Name, OS Name, Address e Last Hardware Scan Date',
                'Gli aggiornamenti Ivanti preservano gli stati excluded e decommissioned',
                'Aggiunta cronologia tecnica degli import Ivanti con conteggi inseriti, aggiornati e scartati',
            ],
        ],
        '1.32' => [
            'date' => '2026-09-08',
            'changes' => [
                'Aggiunto installer completo dedicato a Red Hat Enterprise Linux 10',
                'Aggiunto aggiornamento sicuro con backup PostgreSQL, staging, controlli e rollback dei file',
                'Rimossa la dipendenza da ripgrep per compatibilita con i repository standard RHEL 10',
                'Corretto import Satellite senza colonna status con binding booleano PostgreSQL esplicito',
            ],
        ],
        '1.31' => [
            'date' => '2026-09-08',
            'changes' => [
                'Refactoring pre-release con public come unica document root web',
                'Codice applicativo separato in app, comandi CLI in bin e asset in public/assets',
                'Aggiunti schema iniziale, runner migrazioni e comando sicuro per creare il primo admin',
                'Aggiunti configurazione Apache di esempio, installer e controlli preflight',
                'Dati, test, documentazione e migrazioni rimossi dalla superficie pubblica',
                'Versione applicativa centralizzata e documentazione open source aggiornata',
            ],
        ],
        '1.30' => [
            'date' => '2026-09-06',
            'changes' => [
                'Infrastructure Hosts ridisegnata e ordinata con Satellite a sinistra e Capsule a destra',
                'Etichette Satellite e Capsule estese e hostname infrastrutturali modificabili dagli amministratori',
                'Riassegnazione sicura dei ruoli infrastrutturali usando host gia presenti nell inventario Satellite',
                'Aggiunta gestione logo Customer in Settings con upload PNG, JPEG o WebP e ripristino Acme Corporation',
                'Logo Customer salvato nella tabella app_settings e applicato a login e header del portale',
                'Aggiunta data ultimo reset password alla gestione utenti',
                'Corretta la rimozione utenti e la selezione delle azioni amministrative in Settings',
                'Favicon MoonCrater aggiornata su tutte le pagine con cache busting',
                'Versione software centralizzata e mostrata nel login',
                'Corretti errori JavaScript preesistenti nelle funzioni di esportazione CSV',
            ],
        ],
        '1.29' => [
            'date' => '2026-09-06',
            'changes' => [
                'Aggiunti toggle Show/Hide per Ivanti Hostname e Ivanti Scan Date',
                'Tutte le colonne Ivanti sono ora nascoste di default nella vista unificata',
                'I dati Ivanti restano disponibili nei confronti e negli export CSV',
            ],
        ],
        '1.28' => [
            'date' => '2026-09-06',
            'changes' => [
                'Rebranding open source: MoonCrater - Infrastructure Control Plane Patching',
                'Nuova icona MoonCrater derivata dall artwork originale e favicon applicativa',
                'Rimossi dal portale i loghi e i riferimenti specifici di azienda e cliente',
                'Cliente configurabile tramite CUSTOMER_NAME e CUSTOMER_LOGO con default Acme Corporation',
                'Aggiunto monogramma cliente neutro utilizzato come asset predefinito',
            ],
        ],
        '1.27' => [
            'date'    => '2026-09-03',
            'changes' => [
                'Nuova pagina Migration trends con andamento temporale RHEL 7, 8, 9 e 10',
                'Grafico migrazioni con date degli import sull asse X, valori host visibili e margini corretti',
                'Diff per minor release suddiviso in quattro tabelle RHEL visibili nella stessa pagina',
                'Importazione CSV storici in modalita snapshot senza modificare l inventario operativo',
                'Snapshot automatico delle versioni RHEL durante ogni nuovo import Satellite',
                'Ricalcolo storico basato su last check-in entro 3 giorni dalla data di ciascun CSV',
                'Corretto il conteggio RHEL 10: esclusi gli host unhealthy dagli andamenti di migrazione',
                'Nuova gestione Satellite: excluded invariato, host presenti active o unhealthy, host assenti missing',
                'Host infrastrutturali Satellite e Capsule preservati durante la riconciliazione degli import',
                'Import Satellite reso transazionale con validazione CSV, hostname e separatore automatico',
                'Hardening applicativo: sessioni sicure, timeout, CSRF e mutazioni riservate agli amministratori',
                'Credenziali database spostate nella configurazione ambiente e messaggi di errore sanitizzati',
                'Aggiunti audit log strutturati per login, import e operazioni amministrative',
                'Protezione file sensibili, security header HTTP e rimozione di script ed export non sicuri',
                'Ruoli admin e user applicati alla UI; impedita la cancellazione dell ultimo amministratore',
                'Dashboard ottimizzata con paginazione, query esplicite e soglia Satellite uniforme a 3 giorni',
                'Migliorata la sicurezza della gestione Zabbix e rimossa la generazione HTML dinamica non sicura',
                'Login compatibile con i password manager tramite autocomplete username e current-password',
                'Aggiunti test statici, test di dominio, migrazioni database e documentazione operativa',
            ],
        ],
        '1.26' => [
            'date'    => '2026-07-04',
            'changes' => [
                'Aggiunto statistics10.php — statistiche dedicate RHEL 10 (Coughlan)',
                'Aggiunto link RHEL 10 stats nella card Statistics & Views',
                'Aggiornate icone scala cromatica: 🔴 RHEL7 → 🟠 RHEL8 → 🟢 RHEL9 → 🔵 RHEL10',
                'kernel_stats.php: header allineato alle altre pagine stats con Back to Dashboard',
                'kernel_stats.php: aggiunta card distribuzione OS major con pill colorate per versione',
                'kernel_stats.php: aggiunto filtro role=host per escludere Satellite e Capsule',
                'statistics_zabbix.php: edit/delete/add hostname inline senza reload pagina',
                'statistics_zabbix.php: colonna Zabbix Ver. con edit inline e badge verde/rosso',
                'statistics_zabbix.php: KPI card migrazione Zabbix 7 con barra progresso e %',
                'statistics_zabbix.php: pagina full-width, IP nascosto di default, kernel e CV sempre visibili',
                'Gestione utenti settings.php: aggiunta colonna role (admin/user) alla tabella users',
                'export_users_sql.php: fix TypeError pg_escape_string() — compatibile PostgreSQL 16 e 18',
                'login.php: refactoring CSS modulare (base.css + components.css + style-login.css)',
                'Infrastructure card: rimosso indicatore Unhealthy per SAT (non gestisce se stesso)',
                'Infrastructure card: Location e Last check-in editabili inline direttamente dalla dashboard',
            ],
        ],
        '1.24' => [
            'date'    => '2026-06-14',
            'changes' => [
                'Redesign completo header del precedente branding e badge versione con changelog',
                'Redesign card statistiche inventario: layout flat, colori semantici, border-top accent',
                'Pannello centrale riorganizzato in 5 card su unica riga (Agent Health, Data Ingestion, Stats, Search, Maintenance)',
                'Agent Health & Anomalies: unificazione contatori Unhealthy + IP Anomalies con breakdown per tabella',
                'Consolidamento colonne Action: rimosse duplicazioni Mask Out / Decommission, unica azione ⊘ Exclude / ↩ Re-include',
                'Host esclusi: nascosti di default con badge contatore e bottone show/hide',
                'Filtri Unhealthy/Healthy: corretta esclusione host Missing dalle due viste',
                'Last check-in anomalo evidenziato con ✕ rossa e sfondo rosso in entrambe le tabelle',
                'Satellite Only: aggiunto filtro unhealthy/healthy, fix bug colonna Location, normalizzazione N/A → N/D',
                'Infrastruttura Satellite/Capsule: gestione dedicata tramite colonna role in inventory_satellite e inventory_ivanti',
                'Card Satellite Active: aggiunto contatore +N per host infrastrutturali (viola)',
                'Fix contatore Unhealthy: esclusi host fantasma (os vuoto) e host Missing tramite INNER JOIN',
                'Fix duplicazione host esclusi in tabella Satellite Only tramite matched_sat_keys',
                'Esclusioni lette da inventory_ivanti.status invece di tabella host_exclusions inesistente',
            ],
        ],
        '1.23' => [
            'date'    => '2026-05-26',
            'changes' => [
                'Layout full-width ripristinato per Unified Inventory View',
                'Import Satellite CSV: supporto separatore automatico ; e ,',
                'Filtro Location dinamico da DB',
                'Colonna toggle show/hide per Ivanti OS, Ivanti IP, Sat IP, Kernel',
                'Ordinamento kernel con logica versioning numerica',
                'Note patching con salvataggio AJAX e indicatore visivo note popolate',
            ],
        ],
        '1.22' => [
            'date'    => '2026-05-10',
            'changes' => [
                'Aggiunta tabella Satellite Only',
                'Contatori dinamici: Ivanti Active, Satellite Active, Missing, Coverage, Satellite Only',
                'OS Distribution per Ivanti e Satellite',
                'Filtri: hostname search, missing status, OS major',
                'Export CSV: Unified view e Global (tutte le tabelle)',
                'Backup DB con snapshot tabelle bak_*',
            ],
        ],
    ];
    $current_version = array_key_first($changelog);
    ?>

    <!-- ============================================================
         HEADER PRODOTTO
         ============================================================ -->
    <div style="display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; margin-bottom: 20px; background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.07); border-radius: 8px; gap: 16px;">

        <!-- SINISTRA: identita MoonCrater -->
        <div style="display: flex; align-items: center; gap: 16px;">
            <img src="assets/img/mooncrater-icon-v2.png" alt="MoonCrater" style="height: 48px; width: 48px; object-fit: contain;">
            <div style="border-left: 1px solid rgba(255,255,255,0.1); padding-left: 16px;">
                <div style="font-size: 18px; font-weight: 700; color: #e5e8e8; letter-spacing: 0.3px; line-height: 1.2;">
                    <?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?>
                </div>
                <div style="font-size: 11px; color: #7f8c8d; margin-top: 2px; letter-spacing: 0.3px;">
                    <?= htmlspecialchars($appSubtitle, ENT_QUOTES, 'UTF-8') ?>
                </div>
            </div>
        </div>

        <!-- CENTRO: cliente configurabile -->
        <div style="display: flex; align-items: center; gap: 10px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.06); border-radius: 6px; padding: 8px 16px;">
            <img src="<?= htmlspecialchars($customerLogo, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') ?>" style="height: 28px; width: 28px; object-fit: contain;">
            <div style="border-left: 1px solid rgba(255,255,255,0.1); padding-left: 10px;">
                <div style="font-size: 10px; color: #7f8c8d; text-transform: uppercase; letter-spacing: 0.5px;">Customer</div>
                <div style="font-size: 12px; color: #abb2b9; font-weight: 600;"><?= htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>

        <!-- DESTRA: Versione + User + Logout -->
        <div style="display: flex; align-items: center; gap: 8px;">

            <?php
            $btn_style = "display:inline-flex; align-items:center; gap:6px; background:rgba(155,89,182,0.12); border:1px solid rgba(155,89,182,0.4); border-radius:5px; padding:6px 13px; font-size:12px; font-weight:600; font-family:inherit; color:#9b59b6; white-space:nowrap; text-decoration:none; cursor:pointer;";
            // Role loaded once by the shared authentication bootstrap.
            ?>

            <!-- Badge versione / changelog -->
            <button onclick="document.getElementById('changelog-modal').style.display='flex'"
                    style="<?= $btn_style ?>">
                📋 v<?= $current_version ?>
                <span style="font-size:10px; opacity:0.65; font-weight:normal;"><?= $changelog[$current_version]['date'] ?></span>
            </button>

            <!-- Settings — solo admin -->
            <?php if ($is_admin): ?>
            <a href="settings.php" style="<?= $btn_style ?>">⚙️ Settings</a>
            <?php endif; ?>

            <!-- Utente (non cliccabile) -->
            <span style="<?= $btn_style ?> cursor:default;">
                👤 <strong style="color:#c39bd3;"><?= htmlspecialchars($_SESSION['username']) ?></strong>
            </span>

            <!-- Logout -->
            <form action="logout.php" method="POST" style="margin:0"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><button type="submit" style="<?= $btn_style ?>">Logout</button></form>

        </div>
    </div>

    <!-- MODAL CHANGELOG -->
    <div id="changelog-modal" onclick="if(event.target===this)this.style.display='none'"
         style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.7); z-index: 9999; align-items: center; justify-content: center; padding: 20px;">
        <div style="background: #1a1a2e; border: 1px solid rgba(155,89,182,0.3); border-radius: 10px; width: 100%; max-width: 700px; max-height: 80vh; overflow-y: auto; padding: 28px;">

            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px;">
                <div>
                    <div style="font-size: 16px; font-weight: 700; color: #e5e8e8;">📋 Changelog</div>
                    <div style="font-size: 11px; color: #7f8c8d; margin-top: 2px;">MoonCrater — Infrastructure Control Plane Patching</div>
                </div>
                <button onclick="document.getElementById('changelog-modal').style.display='none'"
                        style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 5px; color: #abb2b9; padding: 6px 12px; cursor: pointer; font-size: 13px;">✕ Close</button>
            </div>

            <?php foreach ($changelog as $ver => $entry): ?>
            <div style="margin-bottom: 24px; padding-bottom: 24px; border-bottom: 1px solid rgba(255,255,255,0.06);">
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px;">
                    <span style="background: <?= $ver === $current_version ? 'rgba(155,89,182,0.2)' : 'rgba(255,255,255,0.05)' ?>; border: 1px solid <?= $ver === $current_version ? 'rgba(155,89,182,0.5)' : 'rgba(255,255,255,0.1)' ?>; color: <?= $ver === $current_version ? '#9b59b6' : '#7f8c8d' ?>; border-radius: 4px; padding: 3px 10px; font-size: 13px; font-weight: bold;">
                        v<?= $ver ?>
                    </span>
                    <span style="font-size: 12px; color: #566573;"><?= $entry['date'] ?></span>
                    <?php if ($ver === $current_version): ?>
                    <span style="background: rgba(46,204,113,0.15); color: #2ecc71; border: 1px solid rgba(46,204,113,0.3); border-radius: 3px; padding: 1px 7px; font-size: 10px; font-weight: bold;">CURRENT</span>
                    <?php endif; ?>
                </div>
                <ul style="margin: 0; padding-left: 18px; display: flex; flex-direction: column; gap: 5px;">
                    <?php foreach ($entry['changes'] as $change): ?>
                    <li style="font-size: 12px; color: #abb2b9; line-height: 1.5;"><?= htmlspecialchars($change) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endforeach; ?>

        </div>
    </div>

    <script>
        // Forza sempre il tema dark — il tema light è disabilitato
        document.documentElement.setAttribute('data-theme', 'dark');
        document.body.setAttribute('data-theme', 'dark');
    </script>


<?php
// ... logica esistente ...

// 1. Fetch the latest database backups from PostgreSQL catalog
$latest_backups = [];
try {
    $stmt_bak = $pdo->query("
        SELECT tablename
        FROM pg_tables
        WHERE schemaname = 'public' AND tablename LIKE 'bak_notes_%'
        ORDER BY tablename DESC
        LIMIT 3
    ");
    $latest_backups = $stmt_bak->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    // Fail silently or log error
}

// 2. Fetch the last modified time of the import script as an indicator
$last_import_date = "Never";
if (file_exists('import_satellite.php')) {
    date_default_timezone_set('Europe/Rome');
    $last_import_date = date("Y-m-d H:i:s", filemtime('import_satellite.php'));
}
?>




<?php if (isset($_GET['backup']) && $_GET['backup'] === 'success'): ?>
    <div style="background-color: #d4edda; color: #155724; padding: 12px 20px; border-radius: 4px; margin-bottom: 20px; border: 1px solid #c3e6cb; display: flex; align-items: center; gap: 10px; font-family: sans-serif;">
        <span style="font-size: 1.2em;">✅</span>
        <div>
            <strong>Backup Successful!</strong><br>
            <small style="color: #155724; opacity: 0.85;">Snapshot tables have been successfully frozen inside the database at local time.</small>
        </div>
    </div>
<?php endif; ?>

<?php if (isset($_GET['ivanti_success'])): ?>
    <div style="background-color: #d4edda; color: #155724; padding: 12px 20px; border-radius: 4px; margin-bottom: 20px; border: 1px solid #c3e6cb; font-family: sans-serif;">
        <strong>Ivanti import completed.</strong>
        <?= (int) $_GET['ivanti_success'] ?> imported,
        <?= (int) ($_GET['ivanti_inserted'] ?? 0) ?> inserted,
        <?= (int) ($_GET['ivanti_updated'] ?? 0) ?> updated,
        <?= (int) ($_GET['ivanti_skipped'] ?? 0) ?> skipped.
    </div>
<?php endif; ?>





    <!-- RIGA 1: 5 stat card inventory -->
    <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; margin-bottom: 12px;">

        <?php
        // Coverage: verde se >= 90%, giallo se >= 70%, rosso sotto
        $cov_val = (float)$coverage;
        $cov_color = $cov_val >= 90 ? '#2ecc71' : ($cov_val >= 70 ? '#f39c12' : '#e74c3c');
        ?>

        <!-- Ivanti Active -->
        <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-top: 3px solid rgba(52,152,219,0.5); border-radius: 6px; padding: 16px 18px; display: flex; flex-direction: column; gap: 6px;">
            <div style="font-size: 11px; color: #7f8c8d; text-transform: uppercase; letter-spacing: 0.5px; font-weight: bold;">Ivanti Active</div>
            <div style="font-size: 32px; font-weight: 700; color: #3498db; line-height: 1;"><?= $active_ivanti_count ?></div>
            <div style="font-size: 11px; color: #566573;">Active Ivanti servers</div>
        </div>

        <!-- Satellite Active -->
        <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-top: 3px solid rgba(52,152,219,0.5); border-radius: 6px; padding: 16px 18px; display: flex; flex-direction: column; gap: 6px;">
            <div style="font-size: 11px; color: #7f8c8d; text-transform: uppercase; letter-spacing: 0.5px; font-weight: bold;">Satellite Active</div>
            <div style="display: flex; align-items: baseline; gap: 8px; line-height: 1;">
                <div style="font-size: 32px; font-weight: 700; color: #3498db;"><?= $active_satellite_count ?></div>
                <?php if (!empty($infra_stats)): ?>
                <div style="font-size: 13px; font-weight: 700; color: #9b59b6;" title="Satellite + Capsule infrastructure servers">
                    +<?= count($infra_stats) ?>
                </div>
                <?php endif; ?>
            </div>
            <div style="font-size: 11px; color: #566573;">Active hosts on Satellite</div>
            <?php if (!empty($infra_stats)): ?>
            <div style="font-size: 10px; color: #9b59b6; margin-top: 2px;">
                +<?= count($infra_stats) ?> Active Satellite/Capsule server<?= count($infra_stats) > 1 ? 's' : '' ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Missing in Satellite -->
        <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-top: 3px solid rgba(231,76,60,0.6); border-radius: 6px; padding: 16px 18px; display: flex; flex-direction: column; gap: 6px;">
            <div style="font-size: 11px; color: #7f8c8d; text-transform: uppercase; letter-spacing: 0.5px; font-weight: bold;">Missing in Sat</div>
            <div style="font-size: 32px; font-weight: 700; color: #e74c3c; line-height: 1;"><?= $missing_count ?></div>
            <div style="font-size: 11px; color: #566573;">Ivanti servers missing in Satellite</div>
        </div>

        <!-- Coverage -->
        <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-top: 3px solid <?= $cov_color ?>88; border-radius: 6px; padding: 16px 18px; display: flex; flex-direction: column; gap: 6px;">
            <div style="font-size: 11px; color: #7f8c8d; text-transform: uppercase; letter-spacing: 0.5px; font-weight: bold;">Coverage</div>
            <div style="font-size: 32px; font-weight: 700; color: <?= $cov_color ?>; line-height: 1;"><?= $coverage ?>%</div>
            <div style="font-size: 11px; color: #566573;">Ivanti servers covered by Satellite</div>
        </div>

        <!-- Satellite Only -->
        <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-top: 3px solid rgba(243,156,18,0.5); border-radius: 6px; padding: 16px 18px; display: flex; flex-direction: column; gap: 6px;">
            <div style="font-size: 11px; color: #7f8c8d; text-transform: uppercase; letter-spacing: 0.5px; font-weight: bold;">Satellite Only</div>
            <div style="font-size: 32px; font-weight: 700; color: #f39c12; line-height: 1;"><?= $satellite_only_count ?></div>
            <div style="font-size: 11px; color: #566573;">Present only in Satellite</div>
        </div>

    </div>

    <!-- RIGA 2: OS Distribution Ivanti + Satellite -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px;">

        <!-- Ivanti OS Distribution -->
        <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 6px; padding: 14px 18px; display: flex; align-items: center; gap: 20px; flex-wrap: wrap;">
            <div style="font-size: 11px; color: #7f8c8d; text-transform: uppercase; letter-spacing: 0.5px; font-weight: bold; white-space: nowrap;">🖥️ Ivanti OS distribution</div>
            <div style="display: flex; gap: 16px; flex-wrap: wrap; align-items: center;">
                <span style="font-size: 13px; color: #566573;">RHEL 7: <strong style="color: <?= $ivanti_rhel7 > 0 ? '#e74c3c' : '#7f8c8d' ?>;"><?= $ivanti_rhel7 ?></strong></span>
                <span style="color: rgba(255,255,255,0.1);">|</span>
                <span style="font-size: 13px; color: #566573;">RHEL 8: <strong style="color: <?= $ivanti_rhel8 > 0 ? '#f39c12' : '#7f8c8d' ?>;"><?= $ivanti_rhel8 ?></strong></span>
                <span style="color: rgba(255,255,255,0.1);">|</span>
                <span style="font-size: 13px; color: #566573;">RHEL 9: <strong style="color: <?= $ivanti_rhel9 > 0 ? '#2ecc71' : '#7f8c8d' ?>;"><?= $ivanti_rhel9 ?></strong></span>
                <span style="color: rgba(255,255,255,0.1);">|</span>
                <span style="font-size: 13px; color: #566573;">RHEL 10: <strong style="color: <?= $ivanti_rhel10 > 0 ? '#3498db' : '#7f8c8d' ?>;"><?= $ivanti_rhel10 ?></strong></span>
                <span style="color: rgba(255,255,255,0.1);">|</span>
                <span style="font-size: 13px; color: #566573;">Unknown: <strong style="color: <?= $ivanti_unknown > 0 ? '#95a5a6' : '#7f8c8d' ?>;"><?= $ivanti_unknown ?></strong></span>
            </div>
        </div>

        <!-- Satellite OS Distribution -->
        <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 6px; padding: 14px 18px; display: flex; align-items: center; gap: 20px; flex-wrap: wrap;">
            <div style="font-size: 11px; color: #7f8c8d; text-transform: uppercase; letter-spacing: 0.5px; font-weight: bold; white-space: nowrap;">🛰️ Satellite OS distribution</div>
            <div style="display: flex; gap: 16px; flex-wrap: wrap; align-items: center;">
                <span style="font-size: 13px; color: #566573;">RHEL 7: <strong style="color: <?= $sat_rhel7 > 0 ? '#e74c3c' : '#7f8c8d' ?>;"><?= $sat_rhel7 ?></strong></span>
                <span style="color: rgba(255,255,255,0.1);">|</span>
                <span style="font-size: 13px; color: #566573;">RHEL 8: <strong style="color: <?= $sat_rhel8 > 0 ? '#f39c12' : '#7f8c8d' ?>;"><?= $sat_rhel8 ?></strong></span>
                <span style="color: rgba(255,255,255,0.1);">|</span>
                <span style="font-size: 13px; color: #566573;">RHEL 9: <strong style="color: <?= $sat_rhel9 > 0 ? '#2ecc71' : '#7f8c8d' ?>;"><?= $sat_rhel9 ?></strong></span>
                <span style="color: rgba(255,255,255,0.1);">|</span>
                <span style="font-size: 13px; color: #566573;">RHEL 10: <strong style="color: <?= $sat_rhel10 > 0 ? '#3498db' : '#7f8c8d' ?>;"><?= $sat_rhel10 ?></strong></span>
                <span style="color: rgba(255,255,255,0.1);">|</span>
                <span style="font-size: 13px; color: #566573;">Unknown: <strong style="color: <?= $sat_unknown > 0 ? '#95a5a6' : '#7f8c8d' ?>;"><?= $sat_unknown ?></strong></span>
            </div>
        </div>

    </div>


    <!-- RIGA 3: Infrastructure (Satellite + Capsule) -->
    <?php if (!empty($infra_stats)): ?>
    <div class="card" style="display:flex;flex-direction:column;margin-bottom:20px;">
        <div style="font-size:11px;color:#85929e;text-transform:uppercase;letter-spacing:.5px;font-weight:bold;margin-bottom:12px;">
            🛰️ Infrastructure hosts — Satellite &amp; Capsule
        </div>
        <div style="display:flex;gap:12px;flex-wrap:wrap;">
            <?php foreach ($infra_stats as $inf):
                $role_color = $inf['role'] === 'satellite' ? '#9b59b6' : '#3498db';
                $role_label = $inf['role'] === 'satellite' ? 'Satellite' : 'Capsule';
                $safe_id    = str_replace(['.', '-'], '_', $inf['hostname']);
                $hostname_json = htmlspecialchars(json_encode($inf['hostname']), ENT_QUOTES, 'UTF-8');
            ?>
            <div style="background:rgba(0,0,0,.15);border:1px solid rgba(255,255,255,.04);border-radius:6px;padding:12px 14px;min-width:320px;flex:1;">

                <!-- Header: badge ruolo + hostname -->
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid rgba(155,89,182,0.15);">
                    <span style="background: <?= $role_color ?>22; color: <?= $role_color ?>; border: 1px solid <?= $role_color ?>55; border-radius: 3px; padding: 2px 7px; font-size: 10px; font-weight: bold; letter-spacing: 0.5px;"><?= $role_label ?></span>
                    <span id="hostname-view-<?= $safe_id ?>" style="display:inline-flex;align-items:center;gap:7px;">
                        <span style="font-size:13px;color:#e5e8e8;font-weight:bold;"><?= htmlspecialchars($inf['hostname']) ?></span>
                        <?php if ($is_admin): ?><button type="button" onclick="infraEditStart('hostname','<?= $safe_id ?>')" title="Edit <?= $role_label ?> hostname" style="border:0;background:transparent;color:#85929e;cursor:pointer;font-size:13px;">✎</button><?php endif; ?>
                    </span>
                    <?php if ($is_admin): ?><span id="hostname-edit-<?= $safe_id ?>" style="display:none;align-items:center;gap:4px;flex:1;">
                        <input id="hostname-input-<?= $safe_id ?>" value="<?= htmlspecialchars($inf['hostname']) ?>" autocomplete="off" spellcheck="false" style="padding:3px 6px;border-radius:4px;border:1px solid <?= $role_color ?>77;background:rgba(255,255,255,.05);color:#fff;font-size:12px;min-width:210px;flex:1;">
                        <button type="button" onclick="infraSave('hostname','<?= $safe_id ?>',<?= $hostname_json ?>)" title="Save" aria-label="Save" style="display:inline-flex;align-items:center;gap:4px;padding:4px 8px;border-radius:4px;border:1px solid rgba(46,204,113,.3);background:rgba(46,204,113,.1);color:#2ecc71;font-size:11px;font-weight:bold;cursor:pointer;">✓ Save</button>
                        <button type="button" onclick="infraCancel('hostname','<?= $safe_id ?>')" title="Cancel" aria-label="Cancel" style="display:inline-flex;align-items:center;gap:4px;padding:4px 8px;border-radius:4px;border:1px solid rgba(231,76,60,.25);background:rgba(231,76,60,.07);color:#e74c3c;font-size:11px;font-weight:bold;cursor:pointer;">✕ Cancel</button>
                    </span><?php endif; ?>
                </div>

                <div style="display: grid; grid-template-columns: auto 1fr; gap: 6px 14px; font-size: 11px; align-items: center;">
                    <div style="color: #7f8c8d; white-space: nowrap;">OS</div>
                    <div style="color: #abb2b9;"><?= htmlspecialchars($inf['os']) ?></div>

                    <div style="color: #7f8c8d; white-space: nowrap;">IP</div>
                    <div style="color: #abb2b9;"><?= htmlspecialchars($inf['ip']) ?></div>

                    <div style="color: #7f8c8d; white-space: nowrap;">Kernel</div>
                    <div style="color: #abb2b9;"><?= htmlspecialchars($inf['kernel']) ?></div>

                    <div style="color: #7f8c8d; white-space: nowrap;">CV Env</div>
                    <div style="color: #abb2b9;"><?= htmlspecialchars($inf['cv_env']) ?></div>

                    <!-- Location — editabile inline -->
                    <div style="color: #7f8c8d; white-space: nowrap;">Location</div>
                    <div>
                        <span id="loc-view-<?= $safe_id ?>"
                              style="color: #abb2b9; cursor: pointer;"
                              title="Click to edit"
                              onclick="infraEditStart('loc', '<?= $safe_id ?>')">
                            📍 <?= htmlspecialchars($inf['location']) ?>
                        </span>
                        <span id="loc-edit-<?= $safe_id ?>" style="display:none; align-items:center; gap:4px;">
                            <input id="loc-input-<?= $safe_id ?>"
                                   value="<?= htmlspecialchars($inf['location']) ?>"
                                   style="padding:3px 6px; border-radius:4px; border:1px solid rgba(155,89,182,0.4); background:rgba(155,89,182,0.08); color:#fff; font-size:11px; width:130px; outline:none;">
                            <button onclick="infraSave('loc','<?= $safe_id ?>','<?= addslashes($inf['hostname']) ?>')" style="padding:2px 7px; border-radius:3px; border:1px solid rgba(46,204,113,0.35); background:rgba(46,204,113,0.1); color:#2ecc71; font-size:11px; cursor:pointer;">✓</button>
                            <button onclick="infraCancel('loc','<?= $safe_id ?>')" style="padding:2px 7px; border-radius:3px; border:1px solid rgba(255,255,255,0.1); background:rgba(255,255,255,0.04); color:#7f8c8d; font-size:11px; cursor:pointer;">✕</button>
                        </span>
                    </div>

                    <!-- Last check-in — editabile inline -->
                    <div style="color: #7f8c8d; white-space: nowrap;">Last check-in</div>
                    <div>
                        <span id="lc-view-<?= $safe_id ?>"
                              style="color: #abb2b9; cursor: pointer;"
                              title="Click to edit"
                              onclick="infraEditStart('lc', '<?= $safe_id ?>')">
                            🕐 <?= htmlspecialchars($inf['last_checkin'] ?: 'N/D') ?>
                        </span>
                        <span id="lc-edit-<?= $safe_id ?>" style="display:none; align-items:center; gap:4px;">
                            <input id="lc-input-<?= $safe_id ?>"
                                   type="date"
                                   value="<?= htmlspecialchars(substr($inf['last_checkin'] ?? '', 0, 10)) ?>"
                                   style="padding:3px 6px; border-radius:4px; border:1px solid rgba(155,89,182,0.4); background:rgba(155,89,182,0.08); color:#fff; font-size:11px; outline:none;">
                            <button onclick="infraSave('lc','<?= $safe_id ?>','<?= addslashes($inf['hostname']) ?>')" style="padding:2px 7px; border-radius:3px; border:1px solid rgba(46,204,113,0.35); background:rgba(46,204,113,0.1); color:#2ecc71; font-size:11px; cursor:pointer;">✓</button>
                            <button onclick="infraCancel('lc','<?= $safe_id ?>')" style="padding:2px 7px; border-radius:3px; border:1px solid rgba(255,255,255,0.1); background:rgba(255,255,255,0.04); color:#7f8c8d; font-size:11px; cursor:pointer;">✕</button>
                        </span>
                    </div>

                    <div style="color: #7f8c8d; white-space: nowrap;">Ivanti scan</div>
                    <div style="color: #abb2b9;"><?= htmlspecialchars($inf['iv_scan_date']) ?></div>
                </div>

            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

<!-- PANNELLO CENTRALE: 5 card su unica riga, larghezza piena -->
<div style="display: grid; grid-template-columns: minmax(200px,1fr) minmax(200px,1fr) minmax(180px,1fr) minmax(380px,2.2fr) minmax(220px,1fr); gap: 12px; margin-bottom: 20px; align-items: stretch;">

    <!-- CARD 1: Agent Health & Anomalies -->
    <div class="card" style="display: flex; flex-direction: column;">
        <p style="font-size: 11px; color: #85929e; text-transform: uppercase; letter-spacing: 0.5px; margin: 0 0 12px 0;">⚠️ Agent health &amp; anomalies</p>

        <!-- Totali -->
        <div style="display: flex; gap: 12px; margin-bottom: 12px; align-items: flex-start;">
            <div style="flex: 1;">
                <div style="font-size: 11px; color: #7f8c8d; margin-bottom: 3px;">Unhealthy hosts</div>
                <div style="font-size: 28px; font-weight: 600; color: #e74c3c; line-height: 1;"><?= $total_unhealthy ?></div>
                <div style="font-size: 10px; color: #7f8c8d; margin-top: 2px;">check-in &gt;<?= SATELLITE_CHECKIN_MAX_AGE_DAYS ?>d, N/D or missing</div>
            </div>
            <div style="width: 1px; background: rgba(255,255,255,0.08); align-self: stretch;"></div>
            <div style="flex: 1;">
                <div style="font-size: 11px; color: #7f8c8d; margin-bottom: 3px;">IP anomalies</div>
                <div style="font-size: 28px; font-weight: 600; color: #e67e22; line-height: 1;"><?= $total_agent_anomalies ?></div>
                <div style="font-size: 10px; color: #7f8c8d; margin-top: 2px;">active hosts without IP</div>
            </div>
        </div>

        <div style="border-top: 1px solid rgba(255,255,255,0.07); padding-top: 8px; display: flex; flex-direction: column; gap: 4px; margin-top: auto;">

            <!-- Riga totale -->
            <div style="display: flex; justify-content: space-between; align-items: center; font-size: 11px; padding: 3px 0;">
                <span style="color: #85929e; font-weight: bold; text-transform: uppercase; letter-spacing: 0.4px;">Total</span>
                <div style="display: flex; gap: 6px;">
                    <span style="background: rgba(46,204,113,0.15); color: #2ecc71; border: 1px solid rgba(46,204,113,0.3); border-radius: 3px; padding: 1px 7px; font-size: 11px; font-weight: bold;">✓ <?= $total_healthy ?></span>
                    <span style="background: rgba(231,76,60,0.12); color: #e74c3c; border: 1px solid rgba(231,76,60,0.25); border-radius: 3px; padding: 1px 7px; font-size: 11px; font-weight: bold;">✕ <?= $total_unhealthy ?></span>
                </div>
            </div>

            <!-- Riga Unified -->
            <div style="display: flex; justify-content: space-between; align-items: center; font-size: 11px; padding: 3px 0; border-top: 1px solid rgba(255,255,255,0.05);">
                <span style="color: #abb2b9;">Unified Inventory</span>
                <div style="display: flex; gap: 6px;">
                    <span style="background: rgba(46,204,113,0.08); color: #2ecc71; border: 1px solid rgba(46,204,113,0.2); border-radius: 3px; padding: 1px 7px; font-size: 11px;">✓ <?= $unified_healthy ?></span>
                    <span style="background: rgba(231,76,60,0.07); color: #e74c3c; border: 1px solid rgba(231,76,60,0.18); border-radius: 3px; padding: 1px 7px; font-size: 11px;">✕ <?= $unified_unhealthy ?></span>
                </div>
            </div>

            <!-- Riga Satellite Only -->
            <div style="display: flex; justify-content: space-between; align-items: center; font-size: 11px; padding: 3px 0; border-top: 1px solid rgba(255,255,255,0.05);">
                <span style="color: #abb2b9;">Satellite Only</span>
                <div style="display: flex; gap: 6px;">
                    <span style="background: rgba(46,204,113,0.08); color: #2ecc71; border: 1px solid rgba(46,204,113,0.2); border-radius: 3px; padding: 1px 7px; font-size: 11px;">✓ <?= $satonly_healthy ?></span>
                    <span style="background: rgba(231,76,60,0.07); color: #e74c3c; border: 1px solid rgba(231,76,60,0.18); border-radius: 3px; padding: 1px 7px; font-size: 11px;">✕ <?= $satonly_unhealthy ?></span>
                </div>
            </div>

        </div>
    </div>

    <!-- CARD 2: Data Ingestion Status -->
    <div class="card" style="display: flex; flex-direction: column; gap: 8px;">
        <p style="font-size: 11px; color: #85929e; text-transform: uppercase; letter-spacing: 0.5px; margin: 0 0 4px 0;">📊 Data ingestion status</p>

        <div style="background: rgba(0,0,0,0.15); padding: 9px 11px; border-radius: 6px; border: 1px solid rgba(255,255,255,0.04);">
            <div style="font-size: 10px; color: #7f8c8d; text-transform: uppercase; margin-bottom: 2px;">Ivanti — last scan date</div>
            <div style="font-size: 14px; font-weight: 600; color: #2ecc71;"><?= htmlspecialchars($last_ivanti_import) ?></div>
        </div>
        <div style="background: rgba(0,0,0,0.15); padding: 9px 11px; border-radius: 6px; border: 1px solid rgba(255,255,255,0.04);">
            <div style="font-size: 10px; color: #7f8c8d; text-transform: uppercase; margin-bottom: 2px;">Red Hat Satellite — last check-in</div>
            <div style="font-size: 14px; font-weight: 600; color: #3498db;"><?= htmlspecialchars($last_satellite_import) ?></div>
        </div>
        <div style="background: rgba(0,0,0,0.15); padding: 9px 11px; border-radius: 6px; border: 1px solid rgba(255,255,255,0.04);">
            <div style="font-size: 10px; color: #7f8c8d; text-transform: uppercase; margin-bottom: 2px;">Last import action</div>
            <div style="font-size: 12px; color: #d5dbdb;"><?= $last_import_date ?></div>
        </div>
        <div style="font-size: 10px; color: #7f8c8d; border-top: 1px solid rgba(255,255,255,0.06); padding-top: 6px; margin-top: auto;">
            ℹ️ Timestamps from PostgreSQL audit records
        </div>
    </div>

    <!-- CARD 3: Statistics & Views -->
    <div class="card" style="display: flex; flex-direction: column; gap: 6px;">
        <p style="font-size: 11px; color: #85929e; text-transform: uppercase; letter-spacing: 0.5px; margin: 0 0 6px 0;">📈 Statistics &amp; views</p>
        <?php
        $stat_links = [
            ['url' => 'statistics7.php',       'label' => 'RHEL 7 stats',  'color' => '#e74c3c'],
            ['url' => 'statistics8.php',       'label' => 'RHEL 8 stats',  'color' => '#f39c12'],
            ['url' => 'statistics9.php',       'label' => 'RHEL 9 stats',  'color' => '#2ecc71'],
            ['url' => 'statistics10.php',      'label' => 'RHEL 10 stats', 'color' => '#3498db'],
            ['url' => 'kernel_stats.php',      'label' => 'Kernel stats',  'icon' => '⚙️'],
            ['url' => 'statistics_zabbix.php', 'label' => 'Zabbix stats',  'icon' => '📡'],
            ['url' => 'migration_trends.php', 'label' => 'Migration trends', 'icon' => '&#8644;'],
            ['url' => 'diff_inventory.php', 'label' => 'Diff Inventory View', 'icon' => '&#8646;'],
        ];
        foreach ($stat_links as $lnk): ?>
            <a href="<?= $lnk['url'] ?>" style="display: flex; align-items: center; justify-content: space-between; padding: 8px 10px; border-radius: 6px; border: 1px solid rgba(255,255,255,0.07); background: rgba(255,255,255,0.03); color: #e5e8e8; text-decoration: none; font-size: 12px; transition: background 0.15s; white-space: nowrap;">
                <span style="display: inline-flex; align-items: center; gap: 7px;">
                    <?php if (isset($lnk['color'])): ?>
                        <span aria-hidden="true" style="display:inline-block; width:10px; height:10px; flex:0 0 10px; border-radius:50%; background:<?= htmlspecialchars($lnk['color']) ?>;"></span>
                    <?php else: ?>
                        <span aria-hidden="true"><?= $lnk['icon'] ?></span>
                    <?php endif; ?>
                    <?= htmlspecialchars($lnk['label']) ?>
                </span>
                <span style="color: #7f8c8d; margin-left: 6px;">›</span>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- CARD 4: Search & Filters (più larga) -->
    <div class="card" style="display: flex; flex-direction: column;">
        <p style="font-size: 11px; color: #85929e; text-transform: uppercase; letter-spacing: 0.5px; margin: 0 0 12px 0;">🔍 Search &amp; filters</p>
        <form method="GET" style="display: flex; flex-direction: column; flex-grow: 1;">

            <div style="display: flex; flex-direction: column; gap: 10px; margin-bottom: 12px;">

                <div style="display: flex; flex-direction: column; gap: 4px;">
                    <label for="search" style="font-size: 12px; color: #abb2b9; font-weight: bold;">Search Hostname:</label>
                    <input type="text" id="search" name="search"
                           value="<?= htmlspecialchars($filter_search) ?>"
                           placeholder="e.g. host01.example.com"
                           style="padding: 7px 10px; border-radius: 4px; border: 1px solid rgba(255,255,255,0.1); background: rgba(0,0,0,0.2); color: #fff; width: 100%; font-size: 13px; box-sizing: border-box;">
                </div>

                <div style="display: flex; flex-direction: column; gap: 4px;">
                    <label style="font-size: 12px; color: #abb2b9; font-weight: bold;">Missing Status:</label>
                    <select name="missing" style="padding: 7px 10px; border-radius: 4px; border: 1px solid rgba(255,255,255,0.1); background: rgba(0,0,0,0.2); color: #fff; width: 100%; font-size: 13px; box-sizing: border-box;">
                        <option value="all"       <?= $filter_missing == 'all'       ? 'selected' : '' ?>>All Systems</option>
                        <option value="missing"   <?= $filter_missing == 'missing'   ? 'selected' : '' ?>>Missing on Satellite</option>
                        <option value="matched"   <?= $filter_missing == 'matched'   ? 'selected' : '' ?>>Present on Satellite</option>
                        <option value="unhealthy" <?= $filter_missing == 'unhealthy' ? 'selected' : '' ?>>⏳ Unhealthy Check-in (&gt;<?= SATELLITE_CHECKIN_MAX_AGE_DAYS ?>d / N/A)</option>
                        <option value="healthy"   <?= $filter_missing == 'healthy'   ? 'selected' : '' ?>>✅ Healthy Check-in (&lt;=<?= SATELLITE_CHECKIN_MAX_AGE_DAYS ?>d)</option>
                    </select>
                </div>

                <div style="display: flex; flex-direction: column; gap: 4px;">
                    <label style="font-size: 12px; color: #abb2b9; font-weight: bold;">OS Major Version:</label>
                    <select name="os" style="padding: 7px 10px; border-radius: 4px; border: 1px solid rgba(255,255,255,0.1); background: rgba(0,0,0,0.2); color: #fff; width: 100%; font-size: 13px; box-sizing: border-box;">
                        <option value="all"     <?= $filter_os == 'all'     ? 'selected' : '' ?>>All Versions</option>
                        <option value="7"       <?= $filter_os == '7'       ? 'selected' : '' ?>>RHEL 7</option>
                        <option value="8"       <?= $filter_os == '8'       ? 'selected' : '' ?>>RHEL 8</option>
                        <option value="9"       <?= $filter_os == '9'       ? 'selected' : '' ?>>RHEL 9</option>
                        <option value="10"      <?= $filter_os == '10'      ? 'selected' : '' ?>>RHEL 10</option>
                        <option value="unknown" <?= $filter_os == 'unknown' ? 'selected' : '' ?>>Unknown</option>
                    </select>
                </div>

                <div style="display: flex; flex-direction: column; gap: 4px;">
                    <label style="font-size: 12px; color: #abb2b9; font-weight: bold;">Location:</label>
                    <select name="location" style="padding: 7px 10px; border-radius: 4px; border: 1px solid rgba(255,255,255,0.1); background: rgba(0,0,0,0.2); color: #fff; width: 100%; font-size: 13px; box-sizing: border-box;">
                        <option value="all" <?= $filter_location == 'all' ? 'selected' : '' ?>>All Locations</option>
                        <?php foreach ($locations_list as $loc): ?>
                            <option value="<?= htmlspecialchars($loc) ?>" <?= $filter_location == $loc ? 'selected' : '' ?>><?= htmlspecialchars($loc) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

            </div>

            <div style="display: flex; gap: 8px; margin-top: auto; padding-top: 10px; border-top: 1px solid rgba(255,255,255,0.06);">
                <a href="index.php" style="flex: 1; text-align: center; padding: 8px 10px; background: rgba(255,255,255,0.07); color: #abb2b9; border-radius: 4px; font-size: 13px; font-weight: bold; text-decoration: none; border: 1px solid rgba(255,255,255,0.1);">Reset</a>
                <button type="submit" style="flex: 2; background: #3498db; color: white; padding: 8px 10px; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 13px;">Apply Filters</button>
            </div>
        </form>
    </div>

    <!-- CARD 5: Maintenance & Export -->
    <div class="card" style="display: flex; flex-direction: column; gap: 8px;">
        <p style="font-size: 11px; color: #85929e; text-transform: uppercase; letter-spacing: 0.5px; margin: 0 0 4px 0;">⚙️ Maintenance &amp; export</p>

        <div style="display: flex; flex-direction: column; gap: 6px;">
            <div style="font-size: 10px; color: #7f8c8d; text-transform: uppercase; letter-spacing: 0.4px; font-weight: bold;">Export CSV</div>
            <button type="button" onclick="exportCSV()" style="display: flex; align-items: center; gap: 8px; width: 100%; padding: 9px 12px; border-radius: 6px; border: 1px solid rgba(255,255,255,0.1); background: rgba(39,174,96,0.1); color: #2ecc71; font-size: 12px; cursor: pointer; text-align: left; font-weight: bold;">
                📊 Unified view only
            </button>
            <button type="button" onclick="exportFullCSV()" style="display: flex; align-items: center; gap: 8px; width: 100%; padding: 9px 12px; border-radius: 6px; border: 1px solid rgba(255,255,255,0.1); background: rgba(52,152,219,0.1); color: #3498db; font-size: 12px; cursor: pointer; text-align: left; font-weight: bold;">
                📊 All views (global)
            </button>
        </div>

        <div style="border-top: 1px solid rgba(255,255,255,0.07); padding-top: 8px; display: flex; flex-direction: column; gap: 6px;">
            <div style="font-size: 10px; color: #7f8c8d; text-transform: uppercase; letter-spacing: 0.4px; font-weight: bold;">Corrective actions</div>
            <form action="backup_db.php" method="POST" style="margin: 0;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <button type="submit" style="width: 100%; padding: 9px 12px; border-radius: 6px; border: 1px solid rgba(255,255,255,0.1); background: rgba(127,140,141,0.15); color: #abb2b9; font-size: 12px; cursor: pointer; font-weight: bold; text-align: left;">
                    📂 Backup DB
                </button>
            </form>
            <form action="import_satellite.php" method="POST" enctype="multipart/form-data" id="importForm" style="margin: 0;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="file" name="satellite_csv" id="fileInput" style="display: none;" onchange="document.getElementById('importForm').submit()">
                <button type="button" onclick="document.getElementById('fileInput').click()" style="width: 100%; padding: 9px 12px; border-radius: 6px; border: 1px solid rgba(255,255,255,0.1); background: rgba(230,126,34,0.12); color: #e67e22; font-size: 12px; cursor: pointer; font-weight: bold; text-align: left;">
                    📥 Import Satellite CSV
                </button>
            </form>
            <form action="import_ivanti.php" method="POST" enctype="multipart/form-data" id="ivantiImportForm" style="margin: 0;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="file" name="ivanti_csv" accept=".csv,text/csv" id="ivantiFileInput" style="display: none;" onchange="document.getElementById('ivantiImportForm').submit()">
                <button type="button" onclick="document.getElementById('ivantiFileInput').click()" style="width: 100%; padding: 9px 12px; border-radius: 6px; border: 1px solid rgba(255,255,255,0.1); background: rgba(52,152,219,0.12); color: #3498db; font-size: 12px; cursor: pointer; font-weight: bold; text-align: left;">
                    📥 Import Ivanti CSV
                </button>
            </form>
        </div>

        <div style="border-top: 1px solid rgba(255,255,255,0.07); padding-top: 8px; margin-top: auto;">
            <div style="font-size: 10px; color: #7f8c8d; text-transform: uppercase; letter-spacing: 0.4px; font-weight: bold; margin-bottom: 6px;">Recent DB snapshots</div>
            <?php if (!empty($latest_backups)): ?>
                <?php foreach ($latest_backups as $bak):
                    $parts = explode('_', $bak);
                    $date_part = $parts[2] ?? '';
                    $time_part = $parts[3] ?? '';
                    if (strlen($date_part) == 8 && strlen($time_part) == 4) {
                        $formatted_bak = substr($date_part,0,4)."-".substr($date_part,4,2)."-".substr($date_part,6,2)." ".substr($time_part,0,2).":".substr($time_part,2,2);
                    } else {
                        $formatted_bak = str_replace('bak_notes_', '', $bak);
                    }
                ?>
                <div style="display: flex; justify-content: space-between; align-items: center; font-size: 11px; color: #d5dbdb; padding: 4px 0; border-bottom: 1px solid rgba(255,255,255,0.05);">
                    <span><?= htmlspecialchars($formatted_bak) ?></span>
                    <span style="background: rgba(46,204,113,0.15); color: #2ecc71; border: 1px solid rgba(46,204,113,0.25); border-radius: 3px; padding: 1px 6px; font-size: 10px; font-weight: bold;">OK</span>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="font-size: 11px; color: #7f8c8d;">No snapshots found</div>
            <?php endif; ?>
        </div>
    </div>

</div>




<div class="card" style="margin-bottom: 15px; padding: 15px;">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
            <div style="display: flex; align-items: center; gap: 20px; flex-wrap: wrap;">
                <span class="column-toggle-label">👁️ Show/Hide Columns:</span>
                <label class="toggle-checkbox-label">
                    <input type="checkbox" id="toggle-ivanti-hostname" onchange="toggleColumn('col-iv-hostname', this.checked)" checked> Ivanti Hostname
                </label>
                <label class="toggle-checkbox-label">
                    <input type="checkbox" id="toggle-ivanti-scan" onchange="toggleColumn('col-iv-scan', this.checked)"> Ivanti Scan Date
                </label>
                <label class="toggle-checkbox-label">
                    <input type="checkbox" id="toggle-ivanti-os" onchange="toggleColumn('col-iv-os', this.checked)"> Ivanti OS
                </label>
                <label class="toggle-checkbox-label">
                    <input type="checkbox" id="toggle-ivanti-ip" onchange="toggleColumn('col-iv-ip', this.checked)"> Ivanti IP
                </label>
                <label class="toggle-checkbox-label">
                    <input type="checkbox" id="toggle-sat-ip" onchange="toggleColumn('col-sat-ip', this.checked)"> Sat IP
                </label>
                <label class="toggle-checkbox-label">
                    <input type="checkbox" id="toggle-kernel" onchange="toggleColumn('col-kernel', this.checked)"> Kernel
                </label>
            </div>

            <!-- Bottone show/hide esclusi -->
            <button id="btn-toggle-excluded"
                    onclick="toggleExcludedRows(this)"
                    style="display: flex; align-items: center; gap: 7px; padding: 7px 14px; border-radius: 5px; border: 1px solid rgba(231,76,60,0.35); background: rgba(231,76,60,0.07); color: #e74c3c; font-size: 12px; font-weight: bold; cursor: pointer; white-space: nowrap;">
                <span id="excluded-eye">🚫</span>
                <span id="excluded-label">Excluded hosts hidden</span>
                <span id="excluded-count" style="background: rgba(231,76,60,0.15); border: 1px solid rgba(231,76,60,0.3); border-radius: 3px; padding: 1px 6px; font-size: 11px; margin-left: 2px;">
                    <?php echo count($exclusions_lookup); ?>
                </span>
            </button>
        </div>
    </div>




    <div class="card">
        <button type="button" id="toggle-unified-inventory" aria-expanded="false" aria-controls="unified-inventory-content" aria-label="Toggle Unified Inventory View" onclick="toggleInventorySection(this, 'unified-inventory-content', 'unified-inventory-arrow', 'unified-inventory-pagination')" style="display:flex;align-items:center;justify-content:space-between;width:100%;padding:4px 2px 10px;border:0;border-bottom:1px solid rgba(255,255,255,.08);background:transparent;color:#e5e8e8;cursor:pointer;text-align:left;">
            <span style="font-size:1.5em;font-weight:700;">Unified Inventory View</span><span id="unified-inventory-arrow" aria-hidden="true" style="font-size:20px;color:#5dade2;transition:transform .15s;">▸</span>
        </button>
        <div id="unified-inventory-content" hidden style="margin-top:16px;">
        <div class="table-wrapper">
            <table class="table" id="mainInventoryTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th class="col-iv-hostname">Ivanti Hostname</th>
                        <th class="col-iv-os col-hidden">Ivanti OS</th>
                        <th class="col-iv-ip col-hidden">Ivanti IP</th>
                        <th class="col-iv-scan col-hidden">Scan Date</th>
                        <th>Sat Hostname</th>
                        <th>Sat OS</th>
                        <th class="col-sat-ip col-hidden">Sat IP</th>
                        <th onclick="sortTabellaKernel()" style="cursor: pointer; color: #3498db;" title="Clicca per ordinare" class="col-kernel col-hidden">
                            Kernel <span id="sortIcon">↕</span>
                        </th>
                        <th>CV Env</th>
                        <th>Location</th>
                        <th>Last Check-in</th>
                        <th style="width: 250px; background-color: #fcf8e3; color: #8a6d3b;">Patching Notes &amp; Plan</th>
                        <th style="text-align: center;">Action</th>
                    </tr>
                </thead>
                <tbody>

            <?php
            $counter = 1;
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 100;
            $filtered_main_count = 0;
            foreach ($merged_rows as $merged) {
                $row = $merged['ivanti'];
                $sat = $merged['satellite'];

                // Gli infrastructure host (Satellite/Capsule) non appaiono nella tabella normale
                if (!empty($merged['is_infra'])) continue;

                $is_missing = !$sat;

                // --- PRE-CALCOLO STATO SALUTE SATELLITE (Soglia 30 Giorni) ---
                // Gli host MISSING non entrano nel filtro unhealthy: il loro problema
                // è l'assenza da Satellite, non il check-in. Usare il filtro "Missing" per loro.
                $is_stale_checkin = false;
                if (!$sat) {
                    // Missing in Satellite: non è un problema di check-in
                    $is_stale_checkin = false;
                } elseif (!empty($sat['last_checkin']) && $sat['last_checkin'] !== 'N/A') {
                    $checkin_date_part = substr($sat['last_checkin'], 0, 10);
                    $checkin_obj = DateTime::createFromFormat('Y-m-d', $checkin_date_part);
                    if ($checkin_obj && $checkin_obj < $satellite_checkin_limit_obj) {
                        $is_stale_checkin = true; // Presente in Satellite ma check-in scaduto
                    }
                } else {
                    // Presente in Satellite ma last_checkin è N/A o vuoto → anomalia reale
                    $is_stale_checkin = true;
                }



                // --- Filtri ---
                // 1. Filtro di Ricerca Hostname
                if (!empty($filter_search)) {
                    $term = strtolower($filter_search);
                    $name_i = strtolower($row['hostname'] ?? '');
                    $name_s = ($sat) ? strtolower($sat['hostname'] ?? '') : '';
                    if (strpos($name_i, $term) === false && strpos($name_s, $term) === false) continue;
                }

                // Logica del filtro "Missing Status / Telemetry" ad un bivio a 4 opzioni
                if ($filter_missing == 'missing'   && !$is_missing) continue;
                if ($filter_missing == 'matched'   &&  $is_missing) continue;
                // Unhealthy e Healthy: escludono sempre gli host missing (usare filtro "Missing" per quelli)
                if ($filter_missing == 'unhealthy' && ($is_missing || !$is_stale_checkin)) continue;
                if ($filter_missing == 'healthy'   && ($is_missing ||  $is_stale_checkin)) continue;

                // 3. Filtro Versione Sistema Operativo (OS Major)
                if ($filter_os != 'all' && detect_os_major($row['os'] ?? '') != $filter_os) continue;

                // 4. CORRETTO: Nuovo Filtro per la Location in PHP Memory
                if ($filter_location !== 'all') {
                    // Recuperiamo la location di Satellite se esiste, altrimenti impostiamo 'N/A'
                    $current_location = ($sat && !empty($sat['location'])) ? $sat['location'] : 'N/A';

                    // Se la location del server corrente non è quella cercata, saltiamo la riga
                    if ($current_location !== $filter_location) {
                        continue;
                    }
                }

                $filtered_main_count++;
                $offset = ($page - 1) * $perPage;
                if ($filtered_main_count <= $offset || $filtered_main_count > $offset + $perPage) continue;

                // --- Logica Date (Soglia critica impostata a 30 giorni) ---
                $scan_date_raw = trim($row['scan_date'] ?? '');
                $date_class = '';
                if (!empty($scan_date_raw) && $scan_date_raw !== 'N/D') {
                    $date_obj = DateTime::createFromFormat('d/m/Y H:i', $scan_date_raw);
                    if (!$date_obj) $date_obj = DateTime::createFromFormat('d/m/Y', substr($scan_date_raw, 0, 10));

                    // Segnalazione se l'ultimo scan Ivanti è più vecchio di 30 giorni
                    if ($date_obj && $date_obj < (new DateTime())->modify('-30 days')) {
                        $date_class = 'warn-text';
                    }
                }

                $checkin_class = '';
                $checkin_display = 'N/D';
                if ($sat && !empty($sat['last_checkin']) && $sat['last_checkin'] !== 'N/A' && $sat['last_checkin'] !== 'N/D') {
                    $checkin_display = $sat['last_checkin'];
                    $checkin_date_part = substr($sat['last_checkin'], 0, 10);
                    $checkin_obj = DateTime::createFromFormat('Y-m-d', $checkin_date_part);

                    // Segnalazione se l'ultimo check-in Satellite è più vecchio di 30 giorni
                    if ($checkin_obj && $checkin_obj < $satellite_checkin_limit_obj) {
                        $checkin_class = 'warn-text';
                    }
                } elseif ($sat) {
                    // Host presente in Satellite ma last_checkin assente o N/A → anomalia
                    $checkin_display = 'N/D';
                    $checkin_class = 'warn-text';
                }
                // Se $sat è null (missing) checkin_display rimane 'N/D' e checkin_class rimane ''
                // perché il problema è l'assenza da Satellite, non il check-in

                $status = $row['status'] ?? 'active';

                // Recuperiamo lo stato definitivo: Satellite ha priorità su Ivanti
                $status = $sat['status'] ?? $row['status'] ?? 'active';

                // Esclusione da host_exclusions (indipendente dall'import)
                $h_key       = strtolower(trim($row['hostname'] ?? ''));
                $is_excluded = isset($exclusions_lookup[$h_key])
                            || isset($exclusions_lookup[normalize_hostname($row['hostname'] ?? '')]);

                // Classe CSS riga
                $row_class = '';
                if ($is_excluded) {
                    $row_class = 'excluded-row';
                } elseif ($is_missing) {
                    $row_class = 'missing';
                }

                ?>

                <tr class="<?= $row_class ?>">
                    <td><?= $counter++ ?></td>
                    <td class="col-iv-hostname"><?= htmlspecialchars($row['hostname'] ?? '') ?></td>
                    <td class="col-iv-os col-hidden"><?= htmlspecialchars($row['os'] ?? '') ?></td>
                    <td class="col-iv-ip col-hidden"><?= htmlspecialchars($row['ip'] ?? '') ?></td>
                    <td class="col-date col-iv-scan col-hidden <?= $date_class ?>"><?= htmlspecialchars($scan_date_raw ?: 'N/D') ?> <?= $date_class ? '⚠️' : '' ?></td>

                    <?php if ($sat): ?>
                        <td><?= htmlspecialchars($sat['hostname'] ?? '') ?></td>
                        <td><?= htmlspecialchars($sat['os'] ?? '') ?></td>
                        <td class="col-sat-ip col-hidden"><?= htmlspecialchars($sat['ip'] ?? '') ?></td>
                        <td class="kernel-cell col-kernel col-hidden"><?= htmlspecialchars($sat['kernel'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($sat['content_view_environment'] ?? 'N/A') ?></td>
                        <td>
                            <span style="padding: 2px 6px; background-color: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 4px; font-size: 0.85em; color: #e5e8e8; font-weight: bold;">
                                📍 <?= htmlspecialchars($sat['location'] ?? 'N/A') ?>
                            </span>
                        </td>
                        <td style="<?= $checkin_class ? 'background: rgba(231,76,60,0.08); color: #e74c3c; font-weight: bold;' : '' ?>">
                            <?php if ($checkin_class): ?>
                                <span style="display: inline-flex; align-items: center; gap: 5px;">
                                    <span style="color: #e74c3c; font-size: 14px;">✕</span>
                                    <span><?= htmlspecialchars($checkin_display) ?></span>
                                </span>
                            <?php else: ?>
                                <?= htmlspecialchars($checkin_display) ?>
                            <?php endif; ?>
                        </td>
                    <?php else: ?>
                        <td class="crit">MISSING</td>
                        <td class="crit">MISSING</td>
                        <td class="col-sat-ip col-hidden crit">MISSING</td>
                        <td class="col-kernel col-hidden crit">MISSING</td>
                        <td class="crit">MISSING</td>
                        <td class="crit">MISSING</td>
                        <td class="crit">MISSING</td>
                    <?php endif; ?>

                        <?php
                            $current_hostname = $row['hostname'];
                            $note_info = $notes_lookup[normalize_hostname($current_hostname)] ?? null;
                            $db_note = trim($note_info['notes'] ?? '');
                            $db_date = trim($note_info['migration_date'] ?? '');
                            $is_populated = (!empty($db_note) || !empty($db_date));
                            $classes = 'note-cell-style' . ($is_populated ? ' note-populated' : '');
                            $safe_html_id = str_replace('.', '-', $current_hostname);
                        ?>
                        <td id="note-td-<?= htmlspecialchars($safe_html_id) ?>" class="<?= $classes ?>">
                            <div style="display: flex; flex-direction: column; gap: 4px;">
                                <input type="date"
                                    onchange="saveNote('<?= addslashes($current_hostname) ?>', this.value, 'date', '<?= addslashes($safe_html_id) ?>')"
                                    value="<?= htmlspecialchars($db_date) ?>"
                                    style="font-size: 0.75em; padding: 2px; border: 1px solid #ccc; border-radius: 3px; width: 100%;">
                                <textarea
                                    onblur="saveNote('<?= addslashes($current_hostname) ?>', this.value, 'text', '<?= addslashes($safe_html_id) ?>')"
                                    placeholder="Patching Notes..."
                                    style="width: 100%; height: 35px; font-size: 0.8em; border: 1px solid #ccc; border-radius: 3px; resize: vertical; font-family: sans-serif;"><?= htmlspecialchars($db_note) ?></textarea>
                                <small id="status-<?= htmlspecialchars($safe_html_id) ?>" style="font-size: 0.65em; color: #27ae60; display: none; font-weight: bold;">✅ Saved</small>
                            </div>
                        </td>

                        <!-- UNICA COLONNA ACTION -->
                        <td style="white-space: nowrap; text-align: center;">
                            <button class="btn-exclude"
                                    onclick="toggleExclude('<?= htmlspecialchars($row['hostname'] ?? $sat['hostname'] ?? '') ?>', this)"
                                    title="<?= $is_excluded ? 'Re-include this host in all counts' : 'Exclude this host from all counts' ?>"
                                    style="padding: 5px 10px; border-radius: 4px; border: 1px solid <?= $is_excluded ? 'rgba(46,204,113,0.4)' : 'rgba(231,76,60,0.4)' ?>; background: <?= $is_excluded ? 'rgba(46,204,113,0.1)' : 'rgba(231,76,60,0.08)' ?>; color: <?= $is_excluded ? '#2ecc71' : '#e74c3c' ?>; font-size: 12px; cursor: pointer; font-weight: bold;">
                                <?= $is_excluded ? '↩ Re-include' : '⊘ Exclude' ?>
                            </button>
                        </td>
                </tr>
            <?php } ?>
        </tbody>


        </table>
    </div>
    </div>
</div>





<?php $main_pages = max(1, (int)ceil($filtered_main_count / $perPage)); if ($main_pages > 1): ?>
<div class="card" id="unified-inventory-pagination" style="display:none;justify-content:center;gap:12px;padding:10px">
<?php if ($page > 1): ?><a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ["page" => $page - 1]))) ?>">Previous</a><?php endif; ?>
<span>Page <?= $page ?> / <?= $main_pages ?></span>
<?php if ($page < $main_pages): ?><a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ["page" => $page + 1]))) ?>">Next</a><?php endif; ?>
</div>
<?php endif; ?>

    <div class="card">
    <button type="button" id="toggle-satellite-only" aria-expanded="false" aria-controls="satellite-only-content" aria-label="Toggle Satellite Only inventory" onclick="toggleInventorySection(this, 'satellite-only-content', 'satellite-only-arrow')" style="display:flex;align-items:center;justify-content:space-between;width:100%;padding:4px 2px 10px;border:0;border-bottom:1px solid rgba(255,255,255,.08);background:transparent;color:#e5e8e8;cursor:pointer;text-align:left;">
        <span style="font-size:1.5em;font-weight:700;">Satellite Only (Not in Ivanti)</span><span id="satellite-only-arrow" aria-hidden="true" style="font-size:20px;color:#5dade2;transition:transform .15s;">▸</span>
    </button>
    <div id="satellite-only-content" hidden style="margin-top:16px;">
    <div class="table-wrapper">
        <table class="table" id="satelliteOnlyTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th class="col-iv-hostname">Ivanti Hostname</th>
                    <th>Satellite Hostname</th>
                    <th>OS</th>
                    <th>IP</th>
                    <th>Kernel</th>
                    <th>Content View</th>
                    <th>Location</th>
                    <th>Last Checkin</th>
                    <th style="width: 250px; background-color: #fcf8e3; color: #8a6d3b;">Patching Notes & Plan</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>

            <?php
            $s_counter = 1;
            foreach ($sat_lookup as $norm_name => $sat_data) {
                if (!isset($matched_sat_keys[$norm_name])) {

                    // --- Calcolo stale checkin per questa riga ---
                    $s_stale_checkin = false;
                    $s_checkin_display = 'N/D';
                    $s_checkin_raw = $sat_data['last_checkin'] ?? '';
                    if (!empty($s_checkin_raw) && $s_checkin_raw !== 'N/A' && $s_checkin_raw !== 'N/D') {
                        $s_checkin_display = $s_checkin_raw;
                        $s_checkin_obj = DateTime::createFromFormat('Y-m-d', substr($s_checkin_raw, 0, 10));
                        if ($s_checkin_obj && $s_checkin_obj < $satellite_checkin_limit_obj) {
                            $s_stale_checkin = true;
                        }
                    } else {
                        // N/A o vuoto = anomalia
                        $s_stale_checkin = true;
                        $s_checkin_display = 'N/D';
                    }

                    // --- Filtri ---
                    if (!empty($filter_search) && strpos(strtolower($sat_data['hostname']), strtolower($filter_search)) === false) continue;
                    if ($filter_os != 'all' && detect_os_major($sat_data['os'] ?? '') != $filter_os) continue;
                    if ($filter_missing == 'unhealthy' && !$s_stale_checkin) continue;
                    if ($filter_missing == 'healthy'   &&  $s_stale_checkin) continue;

                    $s_hkey        = strtolower(trim($sat_data['hostname'] ?? ''));
                    $s_is_excluded = isset($exclusions_lookup[$s_hkey]);
                    $s_class       = $s_is_excluded ? 'excluded-row' : '';

                    $current_sat_hostname = $sat_data['hostname'];
                    $note_info    = $notes_lookup[normalize_hostname($current_sat_hostname)] ?? null;
                    $db_note      = trim($note_info['notes'] ?? '');
                    $db_date      = trim($note_info['migration_date'] ?? '');
                    $is_populated_sat = (!empty($db_note) || !empty($db_date));
                    $classes_sat  = 'note-cell-style' . ($is_populated_sat ? ' note-populated' : '');
                    $safe_sat_html_id = str_replace('.', '-', $current_sat_hostname);
            ?>

                    <tr class="<?= $s_class ?>">
                        <td><?= $s_counter++ ?></td>
                        <td class="col-iv-hostname">N/A</td>
                        <td><?= htmlspecialchars($sat_data['hostname'] ?? '') ?></td>
                        <td><?= htmlspecialchars($sat_data['os'] ?? '') ?></td>
                        <td><?= htmlspecialchars($sat_data['ip'] ?? '') ?></td>
                        <td style="font-size: 0.85em;"><?= htmlspecialchars($sat_data['kernel'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($sat_data['content_view_environment'] ?? '') ?></td>

                        <td>
                            <span style="padding: 2px 6px; background-color: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 4px; font-size: 0.85em; color: #e5e8e8; font-weight: bold;">
                                📍 <?= htmlspecialchars($sat_data['location'] ?? 'N/A') ?>
                            </span>
                        </td>

                        <!-- Last checkin con evidenziazione anomalia -->
                        <td style="<?= $s_stale_checkin ? 'background: rgba(231,76,60,0.08); color: #e74c3c; font-weight: bold;' : '' ?>">
                            <?php if ($s_stale_checkin): ?>
                                <span style="display: inline-flex; align-items: center; gap: 5px;">
                                    <span style="color: #e74c3c; font-size: 14px;">✕</span>
                                    <span><?= htmlspecialchars($s_checkin_display) ?></span>
                                </span>
                            <?php else: ?>
                                <?= htmlspecialchars($s_checkin_display) ?>
                            <?php endif; ?>
                        </td>

                        <td id="note-td-<?= htmlspecialchars($safe_sat_html_id) ?>" class="<?= $classes_sat ?>">
                            <div style="display: flex; flex-direction: column; gap: 4px;">
                                <input type="date"
                                    onchange="saveNote('<?= addslashes($current_sat_hostname) ?>', this.value, 'date', '<?= addslashes($safe_sat_html_id) ?>')"
                                    value="<?= htmlspecialchars($db_date) ?>"
                                    style="font-size: 0.75em; padding: 2px; border: 1px solid #ccc; border-radius: 3px; width: 100%;">
                                <textarea
                                    onblur="saveNote('<?= addslashes($current_sat_hostname) ?>', this.value, 'text', '<?= addslashes($safe_sat_html_id) ?>')"
                                    placeholder="Patching Notes...."
                                    style="width: 100%; height: 35px; font-size: 0.8em; border: 1px solid #ccc; border-radius: 3px; resize: vertical;"><?= htmlspecialchars($db_note) ?></textarea>
                                <small id="status-<?= htmlspecialchars($safe_sat_html_id) ?>" style="font-size: 0.65em; color: #27ae60; display: none; font-weight: bold;">✅ Saved</small>
                            </div>
                        </td>

                        <td style="text-align: center;">
                            <button class="btn-exclude"
                                    onclick="toggleExclude('<?= htmlspecialchars($sat_data['hostname'] ?? '') ?>', this)"
                                    title="<?= $s_is_excluded ? 'Re-include this host in all counts' : 'Exclude this host from all counts' ?>"
                                    style="padding: 5px 10px; border-radius: 4px; border: 1px solid <?= $s_is_excluded ? 'rgba(46,204,113,0.4)' : 'rgba(231,76,60,0.4)' ?>; background: <?= $s_is_excluded ? 'rgba(46,204,113,0.1)' : 'rgba(231,76,60,0.08)' ?>; color: <?= $s_is_excluded ? '#2ecc71' : '#e74c3c' ?>; font-size: 12px; cursor: pointer; font-weight: bold;">
                                <?= $s_is_excluded ? '↩ Re-include' : '⊘ Exclude' ?>
                            </button>
                        </td>

                        </tr>
                    <?php }
                } ?>
                </tbody>
            </table>
        </div>
        </div>
    </div>
</div>

<script>
let sortAsc = false;

function sortTabellaKernel() {
    // 1. Puntiamo alla tabella corretta
    const table = document.getElementById("mainInventoryTable");
    if (!table) {
        console.error("Tabella 'mainInventoryTable' non trovata!");
        return;
    }

    const tbody = table.querySelector("tbody");
    const rows = Array.from(tbody.querySelectorAll("tr"));
    const icon = document.getElementById("sortIcon");

    // 2. Trova l'indice della colonna "Kernel" (dinamico)
    const headers = Array.from(table.querySelectorAll("thead th"));
    const kernelColumnIndex = headers.findIndex(th => th.innerText.includes("Kernel"));

    if (kernelColumnIndex === -1) {
        alert("Errore: Colonna Kernel non identificata.");
        return;
    }

    // 3. Logica di ordinamento
    sortAsc = !sortAsc;
    icon.innerHTML = sortAsc ? "↑" : "↓";

    rows.sort((a, b) => {
        const cellA = a.cells[kernelColumnIndex] ? a.cells[kernelColumnIndex].innerText.trim() : "";
        const cellB = b.cells[kernelColumnIndex] ? b.cells[kernelColumnIndex].innerText.trim() : "";

        // Gestione record mancanti
        const isBadA = (cellA === "" || cellA.includes("MISSING") || cellA === "N/A");
        const isBadB = (cellB === "" || cellB.includes("MISSING") || cellB === "N/A");

        if (isBadA && !isBadB) return 1;
        if (!isBadA && isBadB) return -1;
        if (isBadA && isBadB) return 0;

        // Parsing versione numerica
        const parseVersion = (v) => v.replace(/[^0-9.]/g, '.').split('.').filter(x => x).map(Number);

        const vA = parseVersion(cellA);
        const vB = parseVersion(cellB);

        for (let i = 0; i < Math.max(vA.length, vB.length); i++) {
            const numA = vA[i] || 0;
            const numB = vB[i] || 0;
            if (numA !== numB) {
                return sortAsc ? numA - numB : numB - numA;
            }
        }
        return 0;
    });

    // 4. Aggiorna il DOM
    const fragment = document.createDocumentFragment();
    rows.forEach(row => fragment.appendChild(row));
    tbody.appendChild(fragment);
}


function toggleColumn(columnClass, isChecked) {
    // Seleziona tutte le celle (th e td) che hanno la classe specificata
    const cells = document.querySelectorAll('.' + columnClass);

    cells.forEach(cell => {
        if (isChecked) {
            // Se il checkbox è selezionato, mostra la colonna
            cell.classList.remove('col-hidden');
        } else {
            // Se deselezionato, nascondi la colonna aggiungendo la classe
            cell.classList.add('col-hidden');
        }
    });
}

// --- GESTIONE HOST ESCLUSI ---
// Di default gli host esclusi sono nascosti al caricamento pagina
let excludedVisible = false;

function toggleExcludedRows(btn) {
    excludedVisible = !excludedVisible;

    const eye    = document.getElementById('excluded-eye');
    const label  = document.getElementById('excluded-label');

    // Nascondi/mostra in entrambe le tabelle
    document.querySelectorAll('tr.excluded-row').forEach(row => {
        row.style.display = excludedVisible ? '' : 'none';
    });

    if (excludedVisible) {
        eye.textContent   = '👁️';
        label.textContent = 'Excluded hosts visible';
        btn.style.borderColor  = 'rgba(46,204,113,0.35)';
        btn.style.background   = 'rgba(46,204,113,0.07)';
        btn.style.color        = '#2ecc71';
        document.getElementById('excluded-count').style.background   = 'rgba(46,204,113,0.15)';
        document.getElementById('excluded-count').style.borderColor  = 'rgba(46,204,113,0.3)';
    } else {
        eye.textContent   = '🚫';
        label.textContent = 'Excluded hosts hidden';
        btn.style.borderColor  = 'rgba(231,76,60,0.35)';
        btn.style.background   = 'rgba(231,76,60,0.07)';
        btn.style.color        = '#e74c3c';
        document.getElementById('excluded-count').style.background   = 'rgba(231,76,60,0.15)';
        document.getElementById('excluded-count').style.borderColor  = 'rgba(231,76,60,0.3)';
    }
}

// Quando un host viene escluso via bottone, aggiorna il badge contatore
function updateExcludedCount(delta) {
    const badge = document.getElementById('excluded-count');
    if (badge) {
        const current = parseInt(badge.textContent.trim()) || 0;
        badge.textContent = Math.max(0, current + delta);
    }
}

function toggleInventorySection(button, contentId, arrowId, paginationId = '') {
    const content = document.getElementById(contentId);
    const pagination = paginationId ? document.getElementById(paginationId) : null;
    const arrow = document.getElementById(arrowId);
    const opening = content.hidden;
    content.hidden = !opening;
    button.setAttribute('aria-expanded', opening ? 'true' : 'false');
    arrow.textContent = opening ? '▾' : '▸';
    if (pagination) pagination.style.display = opening ? 'flex' : 'none';
}

document.addEventListener("DOMContentLoaded", function() {
    // Nascondi subito tutti gli host esclusi
    document.querySelectorAll('tr.excluded-row').forEach(row => {
        row.style.display = 'none';
    });

    // Stato iniziale checkbox colonne
    toggleColumn('col-iv-hostname', document.getElementById('toggle-ivanti-hostname').checked);
    toggleColumn('col-iv-scan', document.getElementById('toggle-ivanti-scan').checked);
    toggleColumn('col-iv-os',  document.getElementById('toggle-ivanti-os').checked);
    toggleColumn('col-iv-ip',  document.getElementById('toggle-ivanti-ip').checked);
    toggleColumn('col-sat-ip', document.getElementById('toggle-sat-ip').checked);
    toggleColumn('col-kernel', document.getElementById('toggle-kernel').checked);
});


</script>


<script>
function saveNote(hostname, value, type, safeId) {
    const statusEl = document.getElementById('status-' + safeId);
    const cellTd = document.getElementById('note-td-' + safeId);

    const formData = new FormData();
    formData.append('hostname', hostname);
    formData.append('value', value);
    formData.append('type', type);

    fetch('save_note.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': window.CSRF_TOKEN },
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if(data.success) {
            if (statusEl) {
                statusEl.style.display = 'block';
                setTimeout(() => { statusEl.style.display = 'none'; }, 2000);
            }

            if (cellTd) {
                const dateInput = cellTd.querySelector('input[type="date"]');
                const textArea = cellTd.querySelector('textarea');

                // Controlla se i campi contengono testo reale
                const hasDate = dateInput && dateInput.value.trim() !== "";
                const hasText = textArea && textArea.value.trim() !== "";

                if (hasDate || hasText) {
                    cellTd.classList.add('note-populated');
                } else {
                    cellTd.classList.remove('note-populated');
                }
            }
        }
    })
    .catch(error => console.error('Errore nel salvataggio della nota:', error));
}
</script>

<script>
function exportCSV() {
    // 1. Prendi i dati dalla tabella principale (Unified Inventory)
    const table = document.getElementById("mainInventoryTable");
    let csv = [];

    // Intestazioni personalizzate per il CSV
    csv.push("Ivanti Hostname,Ivanti OS,Ivanti IP,Scan Date,Sat Hostname,Sat OS,Sat IP,Kernel,CV Env,Location,Last Checkin,Migration Date,Notes");

    const rows = table.querySelectorAll("tbody tr");

    rows.forEach(row => {
        // Estraiamo i dati dalle celle (saltiamo l'indice # e le Actions)
        const cols = row.querySelectorAll("td");
        if (cols.length < 10) return;

        let rowData = [
            cols[1].innerText, // Ivanti Hostname
            cols[2].innerText, // Ivanti OS
            cols[3].innerText, // Ivanti IP
            cols[4].innerText.replace('⚠️', ''), // Scan Date
            cols[5].innerText === 'MISSING' ? '' : cols[5].innerText, // Sat Hostname
            cols[6].innerText === 'MISSING' ? '' : cols[6].innerText, // Sat OS
            cols[7].innerText === 'MISSING' ? '' : cols[7].innerText, // Sat IP
            cols[8].innerText === 'MISSING' ? '' : cols[8].innerText, // Kernel
            cols[9].innerText === 'MISSING' ? '' : cols[9].innerText, // CV Env
            cols[10].innerText === 'MISSING' ? '' : cols[10].innerText, // Location
            cols[11].innerText.replace('⏳', ''), // Last Checkin
            // Per le note prendiamo i valori reali dagli input/textarea
            cols[12].querySelector('input') ? cols[12].querySelector('input').value : '',
            cols[12].querySelector('textarea') ? cols[12].querySelector('textarea').value.replace(/\n/g, " ") : ''
        ];

        // Pulizia dati (virgole e virgolette per evitare di rompere il CSV)
        let formattedRow = rowData.map(text => `"${(text || '').trim().replace(/"/g, '""')}"`);
        csv.push(formattedRow.join(","));
    });

    // 2. Creazione del file e download
    const csvContent = "data:text/csv;charset=utf-8," + csv.join("\n");
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);

    const date = new Date().toISOString().slice(0, 10);
    link.setAttribute("download", `inventory_patching_${date}.csv`);
    document.body.appendChild(link);

    link.click();
    document.body.removeChild(link);
}
</script>

<script>
function exportFullCSV() {
    let csv = [];
    // Intestazione del CSV
    csv.push("Source,Hostname,OS,IP,Scan/Checkin,Kernel,CV Env,Migration Date,Notes");

    // --- FUNZIONE INTERNA PER PROCESSARE UNA TABELLA ---
    const processTable = (tableId, sourceLabel) => {
        const table = document.getElementById(tableId);
        if (!table) return;
        const rows = table.querySelectorAll("tbody tr");

        rows.forEach(row => {
            const cols = row.querySelectorAll("td");
            if (cols.length < 5) return; // Salta righe vuote o caricate male

            let rowData = [];

            if (sourceLabel === "Unified") {
                // Mapping per la tabella principale (Ivanti + Sat)
                // Usiamo l'hostname Ivanti o quello Satellite se Ivanti manca
                let hostname = cols[1].innerText.trim() || cols[5].innerText.trim();
                let os = cols[2].innerText.trim();
                let ip = cols[3].innerText.trim();
                let lastDate = cols[11].innerText.replace('⏳', '').trim(); // Last Checkin Sat
                let kernel = cols[8].innerText.replace('MISSING', '').trim();
                let cvEnv = cols[9].innerText.replace('MISSING', '').trim();

                const noteCell = cols[12];
                let migDate = noteCell.querySelector('input') ? noteCell.querySelector('input').value : '';
                let noteText = noteCell.querySelector('textarea') ? noteCell.querySelector('textarea').value : '';

                rowData = [sourceLabel, hostname, os, ip, lastDate, kernel, cvEnv, migDate, noteText];
            } else {
                // Mapping per la tabella Satellite Only (quella in fondo)
                let hostname = cols[2].innerText.trim();
                let os = cols[3].innerText.trim();
                let ip = cols[4].innerText.trim();
                let lastDate = cols[8].innerText.trim();
                let kernel = cols[5].innerText.trim();
                let cvEnv = cols[6].innerText.trim();

                const noteCell = cols[9];
                let migDate = noteCell.querySelector('input') ? noteCell.querySelector('input').value : '';
                let noteText = noteCell.querySelector('textarea') ? noteCell.querySelector('textarea').value : '';

                rowData = ["SatOnly", hostname, os, ip, lastDate, kernel, cvEnv, migDate, noteText];
            }

            // Pulizia e formattazione CSV (escaping virgolette)
            let formattedRow = rowData.map(text => {
                let s = (text || '').toString().replace(/\n/g, " ").replace(/"/g, '""').trim();
                return `"${s}"`;
            });
            csv.push(formattedRow.join(","));
        });
    };

    // --- ESECUZIONE SULLE DUE TABELLE ---
    // Nota: assicurati che la seconda tabella abbia un ID o cercala per classe
    processTable("mainInventoryTable", "Unified");

    processTable("satelliteOnlyTable", "SatOnly");

    // --- DOWNLOAD DEL FILE ---
    const csvString = "\uFEFF" + csv.join("\n"); // Aggiunge BOM per far leggere correttamente le accentate a Excel
    const blob = new Blob([csvString], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");

    const timestamp = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
    link.setAttribute("href", url);
    link.setAttribute("download", `patching_report_global_${timestamp}.csv`);
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}


// ============================================================
// INFRASTRUCTURE CARD — edit inline Location e Last check-in
// ============================================================
function infraEditStart(field, id) {
    document.getElementById(field + '-view-' + id).style.display = 'none';
    const editSpan = document.getElementById(field + '-edit-' + id);
    editSpan.style.display = 'inline-flex';
    document.getElementById(field + '-input-' + id).focus();
}

function infraCancel(field, id) {
    document.getElementById(field + '-edit-' + id).style.display = 'none';
    document.getElementById(field + '-view-' + id).style.display = '';
}

async function infraSave(field, id, hostname) {
    const input = document.getElementById(field + '-input-' + id);
    const value = input.value.trim();

    try {
        const res  = await fetch('action_infra.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN },
            body:    JSON.stringify({ hostname, field, value })
        });
        const data = await res.json();

        if (!data.success) {
            alert('Error: ' + data.error);
            return;
        }

        if (field === 'hostname') {
            window.location.reload();
            return;
        }

        // Aggiorna la view
        const viewSpan = document.getElementById(field + '-view-' + id);
        if (field === 'loc') {
            viewSpan.textContent = '📍 ' + (value || 'N/A');
        } else {
            viewSpan.textContent = '🕐 ' + (value || 'N/D');
        }
        infraCancel(field, id);

    } catch (e) {
        alert('Network error saving infrastructure data');
    }
}

function toggleExclude(hostname, buttonElement) {
    if (!confirm('Change exclusion status for: ' + hostname + '?')) return;

    fetch('toggle_decommission.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN },
        body: JSON.stringify({ hostname: hostname })
    })
    .then(response => {
        if (!response.ok) throw new Error('Network error');
        return response.json();
    })
    .then(data => {
        if (data.success) {
            const row = buttonElement.closest('tr');
            const isNowExcluded = (data.new_status === 'excluded');

            // Aggiorna classe CSS della riga
            if (row) {
                if (isNowExcluded) {
                    row.classList.add('excluded-row');
                    row.classList.remove('missing');
                    // Nascondi subito se la modalità è "nascosti"
                    if (!excludedVisible) row.style.display = 'none';
                    updateExcludedCount(+1);
                } else {
                    row.classList.remove('excluded-row');
                    row.style.display = '';
                    updateExcludedCount(-1);
                }
            }

            // Aggiorna testo e stile del bottone
            if (isNowExcluded) {
                buttonElement.innerHTML = '↩ Re-include';
                buttonElement.style.borderColor = 'rgba(46,204,113,0.4)';
                buttonElement.style.background   = 'rgba(46,204,113,0.1)';
                buttonElement.style.color        = '#2ecc71';
                buttonElement.title = 'Re-include this host in all counts';
            } else {
                buttonElement.innerHTML = '⊘ Exclude';
                buttonElement.style.borderColor = 'rgba(231,76,60,0.4)';
                buttonElement.style.background   = 'rgba(231,76,60,0.08)';
                buttonElement.style.color        = '#e74c3c';
                buttonElement.title = 'Exclude this host from all counts';
            }
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Communication error with toggle_decommission.php');
    });
}

</script>

</body>
</html>
