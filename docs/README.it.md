<p align="center">
  <img src="../public/assets/img/mooncrater-icon-v2.png" alt="Logo MoonCrater" width="180">
</p>

# MoonCrater - Infrastructure Control Plane Patching

[English (primary README)](../README.md)

[![License: AGPL v3 or later](https://img.shields.io/badge/License-AGPL_v3_or_later-blue.svg)](https://www.gnu.org/licenses/agpl-3.0.html)

Portale PHP/PostgreSQL per correlare gli inventari Linux provenienti da Ivanti,
Red Hat Satellite e Zabbix.

Versione applicativa corrente: **1.39**.

Asset branding:

- `docs/MoonCraterIcon-original.jpeg`: artwork originale fornito dall'autore;
- `public/assets/img/mooncrater-icon-v2.png`: icona prodotto;
- `public/assets/img/favicon-mooncrater-130.png`: favicon;
- `public/assets/img/customer-default.svg`: logo cliente predefinito.

## Struttura del progetto

Apache deve pubblicare esclusivamente `public/`. Il codice applicativo è in `app/`, i comandi
amministrativi in `bin/`, le migrazioni in `migrations/` e i dati runtime in `var/`.
Vedere `docs/ARCHITECTURE.md` per i confini e le responsabilità delle directory.

## Funzioni principali

- Dashboard unificata degli host Linux.
- Confronto tra inventari Ivanti e Satellite.
- Stati `active`, `unhealthy`, `missing` ed `excluded`.
- Gestione separata degli host infrastrutturali Satellite e Capsule.
- Statistiche dedicate per RHEL 7, 8, 9 e 10.
- Inventario e avanzamento della migrazione Zabbix.
- Storico degli import Satellite con grafico e diff per minor release RHEL.
- Diff Inventory View tra CSV Ivanti e Satellite, suddivisa per famiglia e release Linux.
- Gestione utenti con ruoli `admin` e `user`.
- Gestione del logo Customer da Settings, con ripristino dell'icona predefinita.
- Assegnazione amministrativa degli hostname ai ruoli Satellite e Capsule.

## Requisiti e configurazione

Sono richiesti Apache HTTPD, PHP con PDO PostgreSQL e PostgreSQL. In produzione
il portale deve essere pubblicato tramite HTTPS.

Configurare nel servizio web o nel secret manager le variabili elencate in
`.env.example`. Il file `.env` non viene letto automaticamente e non deve essere
pubblicato.

Variabili obbligatorie:

- `DB_USER`
- `DB_PASS`

Variabili opzionali:

- `DB_HOST` (default `localhost`)
- `DB_PORT` (default `5432`)
- `DB_NAME` (default `patching`)
- `APP_TIMEZONE` (default `Europe/Rome`)
- `APP_NAME` (default `MoonCrater`)
- `APP_SUBTITLE` (default `Infrastructure Control Plane Patching`)
- `CUSTOMER_NAME` (default `Acme Corporation`)
- `CUSTOMER_LOGO` (default `assets/img/customer-default.svg`; percorso immagine same-origin)

Apache deve usare `public/` come `DocumentRoot` e consentire le direttive presenti in
`public/.htaccess` usando almeno:

```apache
AllowOverride FileInfo Options AuthConfig Limit
```

## Migrazioni database

Con le variabili database disponibili nell'ambiente, applicare tutte le migrazioni in ordine:

```bash
php bin/migrate.php
php bin/create-admin.php admin
```

La migrazione `000` rende installabile un database vuoto. La migrazione `003` crea la baseline
solo se non esistono run storici. Include
esclusivamente host `active` ed esclude i ruoli `satellite` e `capsule`.

## Import Ivanti

Gli amministratori possono importare dalla dashboard il CSV Ivanti con intestazioni `Device Name`,
`OS Name`, `Address` e `Last Hardware Scan Date`. L'import aggiorna o inserisce gli host senza
rimuovere quelli assenti e conserva gli stati `excluded` e `decommissioned`. Da CLI:

```bash
php public/import_ivanti.php /percorso/inventario_ivanti.csv
```

## Import Satellite corrente

L'import operativo è disponibile agli amministratori dalla dashboard oppure da CLI:

```bash
php public/import_satellite.php /percorso/inventario_satellite.csv
```

Se il percorso non è specificato, viene usato il CSV corrente nella directory
`satellite-import-csv/`.

Regole applicate:

- un host `excluded` resta escluso;
- un host presente con check-in entro 30 giorni diventa `active`;
- un host presente con check-in vecchio, assente o non valido diventa `unhealthy`;
- un host assente dal nuovo CSV diventa `missing`;
- i ruoli `satellite` e `capsule` non vengono modificati.

L'import è transazionale e registra automaticamente uno snapshot storico dei soli
host RHEL attivi.

## Import Satellite storico e trend

La pagina `migration_trends.php` consente agli amministratori di caricare vecchi
CSV indicando la data dello snapshot. Questo import non modifica l'inventario
operativo, gli stati, i check-in o le esclusioni correnti.

Sono considerati attivi soltanto gli host con check-in entro 30 giorni dalla data
selezionata, usando le ore 23:59:59 come riferimento.

La pagina mostra:

- andamento RHEL 7, 8, 9 e 10;
- date degli snapshot sull'asse X, distanziate in proporzione al tempo trascorso;
- numero di host su ogni punto;
- quattro tabelle diff, una per ogni major release.

La data manuale del singolo import e opzionale: se non viene indicata, viene estratta dal nome del
file (`DDMMYYYY`, `YYYY-MM-DD` o `YYYYMMDD`, con ora e minuti opzionali). Un nuovo import con lo
stesso nome sostituisce quello precedente. Gli amministratori possono azzerare esclusivamente grafico e storico, senza
toccare l'inventario operativo, oppure selezionare una cartella locale dal browser.

Per ricostruire lo storico usando una directory gia presente sul server:

```bash
sudo php bin/import-satellite-history-dir.php /percorso/export-satellite
```

Il comando ignora i CSV non Satellite e quelli privi di data nel nome.

Per il funzionamento automatico, Satellite puo inviare via SCP file con nome
`export_satellite_completo-DDMMYYYY-HHMM.csv` nella cartella `satellite-import-csv/`. Il timer di
MoonCrater controlla la cartella ogni cinque minuti: importa tutti i file nuovi in ordine temporale,
usa il piu recente per inventario corrente, diff e statistiche, e conserva i precedenti nel grafico
delle migrazioni. La configurazione completa e in `setup/satellite/README.md`.

L'installer e l'updater RHEL 10 creano automaticamente l'account di sistema
`mooncrater-import`, la relativa home e `.ssh`, la cartella di ricezione con i permessi corretti e
le unita systemd. Successivamente occorre installare `setup/satellite/install-on-satellite.sh` sul
server Satellite e autorizzare su MoonCrater la chiave pubblica generata, eseguendo:

```bash
sudo /opt/mooncrater/setup/rhel10/configure-satellite-ingest.sh /percorso/chiave-pubblica.pub
```

L'importatore non e un demone sempre attivo. `mooncrater-satellite-import.service` e un servizio
systemd di tipo `oneshot`: viene avviato dal timer, elabora la cartella e poi torna normalmente nello
stato `inactive (dead)`. La pianificazione persistente da controllare e
`mooncrater-satellite-import.timer`:

```bash
sudo systemctl status mooncrater-satellite-import.timer
sudo systemctl list-timers --all | grep mooncrater
```

Per avviare subito un controllo e consultarne il risultato:

```bash
sudo systemctl start mooncrater-satellite-import.service
sudo systemctl status mooncrater-satellite-import.service
sudo journalctl -u mooncrater-satellite-import.service -n 100 --no-pager
```

## Diff Inventory View

La dashboard contiene un collegamento alla nuova vista di confronto in sola lettura. La pagina usa
i CSV piu recenti presenti in `ivanti-import-csv/` e `satellite-import-csv/`, normalizza gli
hostname e divide i risultati nei riquadri Red Hat Enterprise Linux, Oracle Linux, SUSE Linux,
Ubuntu, CentOS, Retired e Unknown.

Ogni riquadro mostra totali Ivanti e Satellite, contatori per release, anomalie oltre 30 giorni e
ordinamento crescente/decrescente per hostname e sistema operativo di entrambe le fonti. Le righe
con Last Check-in Satellite oltre 30 giorni rispetto alla data dello snapshot sono evidenziate in
giallo fluorescente. Se il CSV Ivanti non contiene Scan Date, viene usata la data di estrazione nel
nome del file. I CSV restano locali, sono esclusi da Git e le relative directory vengono preservate
dagli aggiornamenti RHEL 10.

Nella dashboard, Unified Inventory View e Satellite Only sono chiuse inizialmente e possono essere
aperte cliccando l'intera barra del titolo. Ivanti Hostname e visibile di default; nella tabella
Satellite Only vale sempre `N/A`.

## Import Zabbix

L'import Zabbix è intenzionalmente disponibile solo da CLI:

```bash
php bin/import_zabbix.php /percorso/zabbix_hosts.csv
```

## Backup

Il pulsante di backup crea tabelle snapshot nello stesso database:

- `bak_ivanti_YYYYMMDD_HHMM`
- `bak_satellite_YYYYMMDD_HHMM`
- `bak_notes_YYYYMMDD_HHMM`

Non è un backup completo. Per proteggere l'intero database occorre affiancare un
`pg_dump` conservato su un sistema esterno.

## Sicurezza

- Le mutazioni web richiedono sessione admin, POST e token CSRF.
- Le sessioni usano cookie `HttpOnly`, `SameSite=Strict` e timeout di inattività.
- L'ID di sessione viene rigenerato dopo il login.
- Gli utenti non amministratori hanno un'interfaccia in sola lettura.
- Non è possibile eliminare l'ultimo amministratore.
- Solo `public/` è esposto dal web; CSV, SQL, test, configurazione e documentazione restano fuori.
- Le credenziali database non sono memorizzate nel codice.
- Gli hash delle password utente non sono esportabili dal portale.
- Errori dettagliati ed eventi di audit vengono inviati ai log del server.

## Verifiche

```bash
setup/check.sh
```

Una richiesta non autenticata a `migration_trends.php` deve ricevere un redirect
HTTP `302` verso `login.php`.

## Documentazione operativa

Consultare `docs/INSTALL.md` per installazione e aggiornamento e `docs/ARCHITECTURE.md` per la
struttura interna.

## Licenza

Copyright © 2026 MoonCrater contributors.

MoonCrater è distribuito secondo la **GNU Affero General Public License, versione 3 o successiva**
(`AGPL-3.0-or-later`). Le versioni modificate distribuite o rese disponibili agli utenti tramite
rete devono rispettare gli obblighi di copyleft e disponibilità del codice sorgente previsti dalla
licenza. Consultare il file `LICENSE` per i termini completi.
