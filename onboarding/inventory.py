"""Parse host lists for the Satellite Onboarding preview."""

import re
from dataclasses import dataclass


MAX_INPUT_LENGTH = 64 * 1024
MAX_HOSTS = 500
LABEL = re.compile(r"[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?", re.ASCII)


@dataclass(frozen=True)
class InventoryIssue:
    line: int | None
    message: str


@dataclass(frozen=True)
class InventoryPreview:
    hosts: tuple[str, ...]
    errors: tuple[InventoryIssue, ...]
    warnings: tuple[InventoryIssue, ...]


def parse_inventory(source: str) -> InventoryPreview:
    if len(source) > MAX_INPUT_LENGTH:
        return InventoryPreview((), (InventoryIssue(None, "Inventory is too large."),), ())

    lines = source.removeprefix("\ufeff").splitlines()
    ini = any(line.strip().startswith("[") for line in lines)
    section = None
    hosts: dict[str, int] = {}
    errors = []
    warnings = []

    for number, raw in enumerate(lines, 1):
        line = raw.strip()
        if not line or line.startswith(("#", ";")):
            continue
        if ini and line.startswith("["):
            section = line
            if section == "[targets:vars]":
                warnings.append(InventoryIssue(number, "Inventory variables were ignored."))
            elif section != "[targets]":
                errors.append(InventoryIssue(number, "Unsupported inventory section."))
            continue
        if ini and section != "[targets]":
            if section is None:
                errors.append(InventoryIssue(number, "Expected a [targets] section."))
            continue

        if "|" in line:
            fields = [field.strip() for field in line.split("|")]
            if len(fields) != 3 or not all(re.fullmatch(r"[A-Za-z0-9_-]+", field) for field in fields[:2]):
                errors.append(InventoryIssue(number, "Expected site|asset|hostname."))
                continue
            line = fields[2]

        hostname = line.removesuffix(".").lower()
        labels = hostname.split(".")
        if (
            len(hostname) > 253
            or not all(LABEL.fullmatch(label) for label in labels)
            or labels[-1].isdigit()
        ):
            errors.append(InventoryIssue(number, "Expected one hostname or FQDN."))
            continue
        if hostname in hosts:
            warnings.append(InventoryIssue(number, f"Duplicate of line {hosts[hostname]}."))
            continue
        if len(hosts) == MAX_HOSTS:
            errors.append(InventoryIssue(number, f"Limit of {MAX_HOSTS} hosts exceeded."))
            break
        hosts[hostname] = number

    if not hosts and not errors:
        errors.append(InventoryIssue(None, "No target hosts found."))
    return InventoryPreview(tuple(hosts), tuple(errors), tuple(warnings))
