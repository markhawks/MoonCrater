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
# Satellite exporter compatibility

The Satellite exporter also works with MoonCrater 1.51 installed via RPM. Complete
the [MoonCrater server setup](../rhel10/README.md) before configuring SSH delivery.
## Onboarding precheck with Hammer

MoonCrater 1.54 queries SSH command `mooncrater-host-search` with a list of
hostnames on standard input. Install the updated wrapper before upgrading
MoonCrater. Copy these files from the release to the Satellite server:

| Release file | Destination on Satellite | Mode |
|---|---|---|
| `mooncrater-hammer-readonly` | `/usr/local/sbin/mooncrater-hammer-readonly` | `0755` |
| `mooncrater-hammer.conf.example` | `/etc/mooncrater-hammer.conf` | `0600` |

From the directory containing the files, as root on Satellite:

```bash
install -o root -g root -m 0755 mooncrater-hammer-readonly /usr/local/sbin/mooncrater-hammer-readonly
if test ! -e /etc/mooncrater-hammer.conf; then
    install -o root -g root -m 0600 mooncrater-hammer.conf.example /etc/mooncrater-hammer.conf
fi
```

Set `MOONCRATER_SATELLITE_ORGANIZATION` in the configuration to the organization
the Hammer account may query. For a new setup, add each MoonCrater public key
to that SSH account's `authorized_keys` with this prefix:

```text
restrict,command="/usr/local/sbin/mooncrater-hammer-readonly" ssh-ed25519 PUBLIC_KEY
```

On MoonCrater, configure `/etc/mooncrater/onboarding-hammer.json` from the
[example](../rhel10/onboarding-hammer.json.example). Pin Satellite's verified
SSH host key and provision the separate web and worker keys as described in
the [onboarding guide](../../docs/SATELLITE_ONBOARDING.md).

Test the filtered command from MoonCrater with a known hostname:

```bash
printf '%s\n' host.example.test |
  sudo -u apache ssh -F /dev/null -o BatchMode=yes -o StrictHostKeyChecking=yes \
    -o IdentitiesOnly=yes -o IdentityAgent=none \
    -o UserKnownHostsFile=/etc/mooncrater/onboarding_known_hosts \
    -i /etc/mooncrater/onboarding_ssh_key \
    HAMMER_USER@SATELLITE_FQDN mooncrater-host-search
```

A known host returns an English JSON object inside the array. A successful
search with no matches returns an empty array.
