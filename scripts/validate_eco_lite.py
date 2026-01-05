"""
Dev-only helper to validate the ECO-lite opening dataset.

Checks:
- JSON parses and each entry has eco/name/moves fields.
- No duplicate move strings.
- Prints entry count and file size for quick inspection.
"""

from __future__ import annotations

import json
from collections import Counter
from pathlib import Path
import sys


def main() -> int:
    data_path = Path(__file__).resolve().parent.parent / "assets" / "data" / "eco_lite.json"
    try:
        content = data_path.read_text(encoding="utf-8")
        data = json.loads(content)
    except Exception as exc:  # noqa: BLE001 - surface the exact parse failure
        print(f"Failed to load {data_path}: {exc}", file=sys.stderr)
        return 1

    if not isinstance(data, list):
        print("Dataset root must be a JSON array.", file=sys.stderr)
        return 1

    missing = []
    for idx, entry in enumerate(data):
        if not isinstance(entry, dict):
            missing.append(idx)
            continue
        for key in ("eco", "name", "moves"):
            if key not in entry:
                missing.append(idx)
                break

    if missing:
        print(f"Entries missing required keys: {missing}", file=sys.stderr)
        return 1

    move_counts = Counter(entry["moves"] for entry in data)
    duplicates = [moves for moves, count in move_counts.items() if count > 1]
    if duplicates:
        print(f"Duplicate move strings found ({len(duplicates)}): {duplicates}", file=sys.stderr)
        return 1

    byte_size = len(content.encode("utf-8"))
    print(f"ECO-lite entries: {len(data)}")
    print(f"Approximate file size: {byte_size} bytes")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
