#!/usr/bin/env bash
# Fail when Clover statement coverage is below the given percent (REQ-TEST-003 / REQ-TEST-006).
set -eu

FILE="${1:-coverage.xml}"
MIN="${2:-99}"

if [ ! -f "$FILE" ]; then
  echo "ERROR: coverage file not found: $FILE" >&2
  exit 1
fi

python3 - "$FILE" "$MIN" <<'PY'
import sys
import xml.etree.ElementTree as ET

path, minimum = sys.argv[1], float(sys.argv[2])
root = ET.parse(path).getroot()
project = root.find("project")
metrics = None if project is None else project.find("metrics")
if metrics is None:
    print("ERROR: project metrics missing in", path, file=sys.stderr)
    sys.exit(1)
statements = int(metrics.get("statements") or 0)
covered = int(metrics.get("coveredstatements") or 0)
if statements == 0:
    print("ERROR: no statements in", path, file=sys.stderr)
    sys.exit(1)
percent = 100.0 * covered / statements
print(f"PHP statement coverage: {percent:.2f}% ({covered}/{statements}), minimum {minimum:.0f}%")
if percent + 1e-9 < minimum:
    sys.exit(1)
PY
