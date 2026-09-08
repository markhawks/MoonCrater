# Automatic Red Hat Satellite feed

MoonCrater can receive timestamped Satellite exports over SSH and process its inbox every five
minutes. Files are ordered by the timestamp in their name. Older files populate Migration Trends;
the newest file is also reconciled against the current operational inventory.

## 1. Configure the MoonCrater server

Create an SSH key on the Satellite server (do not set a passphrase for an unattended systemd or
cron job), copy only its public key to the MoonCrater server, then run:

```bash
sudo /opt/mooncrater/setup/rhel10/configure-satellite-ingest.sh /root/satellite-export.pub
```

This creates the dedicated `mooncrater-import` account and grants it access only to the inbox at
`/opt/mooncrater/satellite-import-csv`. The normal RHEL installer and updater install and enable
`mooncrater-satellite-import.timer`.

## 2. Configure the Satellite server

Copy both `install-on-satellite.sh` and `export-and-send.sh` to the same temporary directory on the
Satellite server, then run:

```bash
chmod 0700 install-on-satellite.sh
sudo ./install-on-satellite.sh
```

The installer asks for the MoonCrater server hostname or IP address. It then creates
`/root/mooncrater-script/export_satellite_completo.sh`, installs and enables `crond`, generates a
dedicated SSH key, writes the destination configuration, and schedules the export every day at
exactly 02:00. It prints the public key and the command needed to authorize it on the MoonCrater
server. After authorizing the key, run the installed export script once manually.

The remaining defaults are:

- SSH user: `mooncrater-import`;
- inbox: `/opt/mooncrater/satellite-import-csv`.

For an unattended installation, supply the destination with `MOONCRATER_HOST`. Override the other
values with `MOONCRATER_SSH_USER` and `MOONCRATER_INBOX`. The export is
named `export_satellite_completo-DDMMYYYY-HHMM.csv`. SCP first writes a hidden `.part` file; the
final atomic rename makes it visible to MoonCrater only after transfer completion.

The installed `/etc/cron.d/mooncrater-satellite-export` entry is:

```cron
0 2 * * * root /root/mooncrater-script/export_satellite_completo.sh >>/var/log/mooncrater-satellite-export.log 2>&1
```

## Operations

```bash
systemctl status mooncrater-satellite-import.timer
journalctl -u mooncrater-satellite-import.service
sudo systemctl start mooncrater-satellite-import.service
```

Successfully imported CSV files remain in the inbox as the source archive. Repeated timer runs skip
files already recorded in the database. A failed file is retried on the next run.
