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
The current UI queues simulations and localhost tests only.
