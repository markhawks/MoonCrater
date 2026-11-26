# MoonCrater — Satellite Onboarding

## Product direction

Satellite Onboarding becomes MoonCrater's primary workflow. Administrators submit a list of servers, select an approved onboarding profile, and use Ansible to register the servers with Red Hat Satellite. The portal shows the outcome for each host so administrators can identify failures and retry the affected hosts.

Diff Inventory remains available as a secondary, read-only tool under Statistics & Views. It continues to support External and Satellite reconciliation and reporting.

## Incremental migration from PHP to Python

- Build new functionality in Python. Migrate existing PHP features as they are revisited, starting with the dashboard once Onboarding is available.
- Serve both applications under the same portal. Apache continues to serve PHP and routes `/onboarding/` to the Python service.
- Keep a single login and reuse the existing users and roles. Define and verify how Python receives the authenticated identity and enforces administrator permissions.
- Use the existing PostgreSQL instance with dedicated tables for onboarding jobs and per-host outcomes.
- Run Ansible through a separate worker. Use approved playbooks, validated inputs, and credentials managed by the execution environment. Record job progress and per-host outcomes for later review.

## First milestone

Complete one end-to-end onboarding against an authorized test server. An existing administrator signs in, submits one host with an approved profile, starts a job, and sees the result in the portal. Verify access control, the asynchronous execution path, and the reported Satellite registration result before expanding to lists of hosts.

The first implementation input is an existing single-host onboarding playbook, or its expected contract. Confirm the required host fields, profile variables, how credentials are supplied, and how the playbook reports success and failure. Use a sanitized copy for review.
## Hammer precheck and local worker

The registration playbook's tested Galaxy dependency is `theforeman.foreman`
5.11.0. The next RPM will ship `onboarding/ansible-requirements.yml`. Provision
the collection for the account that runs Ansible, for example with
`ansible-galaxy collection install -r /opt/mooncrater/onboarding/ansible-requirements.yml`.
Galaxy access or an approved offline collection archive is required.

The precheck shows a FQDN from the inventory match, the submitted FQDN, or
MoonCrater's system resolver. DNS resolution alone does not verify Satellite registration.

On Satellite, install `setup/satellite/mooncrater-hammer-readonly` as a
root-owned executable at `/usr/local/sbin/mooncrater-hammer-readonly`. Create
a root-owned `/etc/mooncrater-hammer.conf` defining
`MOONCRATER_SATELLITE_ORGANIZATION`. Configure Hammer with a read-only Satellite
identity for that organization. Use a dedicated SSH key and pin the verified
Satellite SSH host key. An authorized_keys entry for this key uses
`restrict,command="/usr/local/sbin/mooncrater-hammer-readonly"`.

On MoonCrater, provision `/etc/mooncrater/onboarding-hammer.json` with keys
`host` and `user`, and install the private key and pinned known-hosts file as
`/etc/mooncrater/onboarding_ssh_key` and `/etc/mooncrater/onboarding_known_hosts`.
The PHP runtime account needs read access. Restrict the private key to that
account with mode 0600. A configured profile fails the precheck on SSH, Hammer,
or output errors. With no profile, the precheck uses the local import. The
current adapter reads the Hammer host list. The wrapper forces English output
with `LANG=C.UTF-8 LC_ALL=C.UTF-8 LANGUAGE=en`. A missing check-in field remains blank.

The local worker only runs the fixed localhost test playbook. On a standard
`/opt/mooncrater` installation, install the units and enable the timer after
applying database migrations:

```bash
sudo install -m 0644 setup/systemd/mooncrater-onboarding-local.{service,timer} /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now mooncrater-onboarding-local.timer
```

Before integrating the customer registration playbook, remove the task that
prints the generated registration command. Set `no_log: true` on registration
command generation and execution, install the approved CA, and confirm the
effect of repository removal and forced registration before allowing a real run.
## Real registration from the portal

Real registration is opt-in. The portal requires a live Hammer precheck, a
resolved FQDN, and explicit operator approval. Jobs handle up to 20 hosts,
one at a time. The worker rechecks registration before calling Ansible.
It verifies the result with Hammer and updates that host's inventory row.
The full import remains responsible for reconciling the entire inventory.

Before enabling the worker on a standard installation:

1. Provision a dedicated system account and group named `mooncrater-onboard`.
   Give it read access to the MoonCrater database configuration and the
   existing read-only Hammer SSH profile and pinned host key. OpenSSH requires
   a private-key file usable by the worker. Set `MOONCRATER_HAMMER_KEY` in
   `/etc/mooncrater/onboarding-worker.env` to a worker-owned key with an
   independent forced-command authorization on Satellite.
2. Install the pinned Galaxy collection for that account and create a
   root-managed `/etc/mooncrater/onboarding-ansible.cfg` pointing to the
   installed collection. Provision verified SSH host keys for the target hosts.
   The portal asks for one target SSH username and password per job over HTTPS.
   Ansible uses `sshpass` with the OpenSSH connection plugin.
3. Create a protected, root-managed
   `/etc/mooncrater/onboarding-registration.yml` readable by the worker.
   Required variables are `satellite_host`, `satellite_user`,
   `satellite_password`, `satellite_organization` and the `capsule` mapping
   for PROD and NPE. Configure `hp0_activation_key` if HP0 can fall outside
   the TF description rules. Install the client CA on the worker and on the
   targets that will connect to Capsule. Use the authorized network route.
4. Install the registration service and timer under `/etc/systemd/system/`.
   After provisioning and testing those prerequisites, create the root-owned
   `/etc/mooncrater/onboarding-registration.enabled` marker, reload systemd,
   and enable `mooncrater-onboarding-registration.timer`.

The bundled playbook preserves existing repository files. It uses forced
registration only for targets confirmed as already present on Satellite.
Logs in the GUI show the outcome per host and the stage that failed.

### Target credential encryption

Before enabling registration, generate a dedicated RSA 3072-bit key pair on
MoonCrater and apply migration `011_onboarding_ssh_credentials.sql`.
The public key is readable by Apache and the private key is readable only by
root and the worker group. Generate the keys once on a fresh configuration:

```bash
sudo bash -c 'set -e; umask 077
cd /etc/mooncrater
test ! -e onboarding-credentials.key.pem && test ! -e onboarding-credentials.pub.pem
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:3072 -out onboarding-credentials.key.pem
openssl pkey -in onboarding-credentials.key.pem -pubout -out onboarding-credentials.pub.pem
chgrp mooncrater-onboard onboarding-credentials.key.pem
chmod 0640 onboarding-credentials.key.pem
chmod 0644 onboarding-credentials.pub.pem'
```

SSH credentials are sealed in the queue and removed from the job row when the
worker claims it. The worker accepts credentials only for jobs queued within
15 minutes and passes them through a mode-0600 temporary file on the service's
private memory filesystem. The password does not appear in the command-line
arguments. Backups taken
while a job is queued may contain encrypted credentials.
