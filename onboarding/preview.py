"""Offline preview adapter for the authenticated portal."""

import json
import sys
from dataclasses import asdict

from inventory import MAX_INPUT_LENGTH, parse_inventory


def main() -> None:
    inventory = parse_inventory(sys.stdin.read(MAX_INPUT_LENGTH + 1))
    print(json.dumps({
        "hosts": inventory.hosts,
        "errors": [asdict(issue) for issue in inventory.errors],
        "warnings": [asdict(issue) for issue in inventory.warnings],
    }))


if __name__ == "__main__":
    main()
