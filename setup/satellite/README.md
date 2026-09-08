# Automatic Red Hat Satellite feed

MoonCrater can receive timestamped Satellite exports over SSH and process its inbox every five
minutes. Files are ordered by the timestamp in their name. Older files populate Migration Trends;
the newest file is also reconciled against the current operational inventory.

## 1. Install or update the MoonCrater server

The RHEL 10 installer and updater automatically create:

- the dedicated `mooncrater-import` operating-system account and its home directory;
- `/home/mooncrater-import/.ssh` with protected permissions;
- the writable inbox `/opt/mooncrater/satellite-import-csv`;
- `mooncrater-satellite-import.timer` and its `oneshot` service.

No SSH public key is installed at this stage because it will be generated on the Satellite server.

## 2. Install the exporter on the Satellite server

Copy both `install-on-satellite.sh` and `export-and-send.sh` to the same temporary directory on the
Satellite server, then run:

```bash
chmod 0700 install-on-satellite.sh
sudo ./install-on-satellite.sh
```

The installer asks for the MoonCrater server hostname or IP address. It then creates
`/root/mooncrater-script/export_satellite_completo.sh`, installs and enables `crond`, generates a
dedicated SSH key without a passphrase, writes the destination configuration, and schedules the
export every day at exactly 02:00.

## 3. Authorize the Satellite public key on MoonCrater

Copy only the public key printed by the Satellite installer to a temporary file on MoonCrater and
run:

```bash
sudo /opt/mooncrater/setup/rhel10/configure-satellite-ingest.sh /path/to/mooncrater_export_ed25519.pub
```

This installs `authorized_keys` with the correct ownership and permissions. Then run the exporter
once manually on Satellite to verify the complete export, SCP delivery, and MoonCrater import:

```bash
/root/mooncrater-script/export_satellite_completo.sh
```

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

The importer is not a continuously running daemon. `mooncrater-satellite-import.service` is a
`oneshot` unit: it starts when requested by the timer, processes the inbox, and then normally
returns to `inactive (dead)`. The persistent component visible between imports is
`mooncrater-satellite-import.timer`.

Check that the timer is enabled and see its next execution:

```bash
sudo systemctl status mooncrater-satellite-import.timer
sudo systemctl list-timers --all | grep mooncrater
```

Check the installed unit definitions:

```bash
sudo ls -l /etc/systemd/system/mooncrater-satellite-import.*
sudo systemctl cat mooncrater-satellite-import.timer
sudo systemctl cat mooncrater-satellite-import.service
```

Start an inbox import immediately and inspect its result:

```bash
sudo systemctl start mooncrater-satellite-import.service
sudo systemctl status mooncrater-satellite-import.service
sudo journalctl -u mooncrater-satellite-import.service -n 100 --no-pager
```

If the timer was not installed during an update, reinstall and enable the automation from the Git
checkout, adapting the installation path when it is not `/opt/mooncrater`:

```bash
sudo MOONCRATER_INSTALL_DIR=/opt/mooncrater \
  ./setup/rhel10/install-satellite-automation.sh
sudo systemctl daemon-reload
sudo systemctl enable --now mooncrater-satellite-import.timer
```

Successfully imported CSV files remain in the inbox as the source archive. Repeated timer runs skip
files already recorded in the database. A failed file is retried on the next run.
