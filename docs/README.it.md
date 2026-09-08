<p align="center">
  <img src="../public/assets/img/mooncreater-icon-v2.png" alt="Logo MoonCreater" width="180">
</p>

# MoonCreater - Infrastructure Control Plane Patching

[English (primary README)](../README.md)

[![License: AGPL v3 or later](https://img.shields.io/badge/License-AGPL_v3_or_later-blue.svg)](https://www.gnu.org/licenses/agpl-3.0.html)

Portale PHP/PostgreSQL per correlare gli inventari Linux provenienti da Ivanti,
Red Hat Satellite e Zabbix.

Versione applicativa corrente: **1.34**.

Asset branding:

- `docs/MoonCreaterIcon-original.jpeg`: artwork originale fornito dall'autore;
- `public/assets/img/mooncreater-icon-v2.png`: icona prodotto;
- `public/assets/img/favicon-mooncreater-130.png`: favicon;
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
- `APP_NAME` (default `MoonCreater`)
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
- date degli import sull'asse X;
- numero di host su ogni punto;
- quattro tabelle diff, una per ogni major release.

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

Copyright © 2026 MoonCreater contributors.

MoonCreater è distribuito secondo la **GNU Affero General Public License, versione 3 o successiva**
(`AGPL-3.0-or-later`). Le versioni modificate distribuite o rese disponibili agli utenti tramite
rete devono rispettare gli obblighi di copyleft e disponibilità del codice sorgente previsti dalla
licenza. Consultare il file `LICENSE` per i termini completi.
