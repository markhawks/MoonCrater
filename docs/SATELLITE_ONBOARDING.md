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
