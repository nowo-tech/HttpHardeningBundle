#!/usr/bin/env bash
# Fail when nowo-tech/HttpHardeningBundle has unresolved open pull requests (REQ-REL-003).
set -eu

REPO="${1:-nowo-tech/HttpHardeningBundle}"

if ! command -v gh >/dev/null 2>&1; then
  echo "ERROR: gh CLI is required for check-open-prs (REQ-REL-003)." >&2
  exit 1
fi

json="$(gh pr list --repo "$REPO" --state open --limit 100 --json number,title,labels,body,mergeable,mergeStateStatus)"

python3 - "$json" <<'PY'
import json
import re
import sys
from datetime import date

prs = json.loads(sys.argv[1])
today = date.today()
bad = []

for pr in prs:
    labels = {str(label.get("name", "")).lower() for label in pr.get("labels") or []}
    held = "hold" in labels or "do-not-merge" in labels
    match = re.search(r"review-by:\s*(\d{4}-\d{2}-\d{2})", pr.get("body") or "", re.I)
    review = date.fromisoformat(match.group(1)) if match else None
    state = str(pr.get("mergeStateStatus") or "")
    conflicted = state in {"DIRTY", "CONFLICTING"} or pr.get("mergeable") == "CONFLICTING"
    valid_hold = held and review is not None and review >= today
    if conflicted and not valid_hold:
        bad.append(pr)
        continue
    if not valid_hold:
        bad.append(pr)

if bad:
    print("ERROR: unresolved open pull requests (REQ-REL-003):", file=sys.stderr)
    for pr in bad:
        print(
            f"  #{pr['number']} {pr['title']} mergeStateStatus={pr.get('mergeStateStatus')}",
            file=sys.stderr,
        )
    sys.exit(1)

print(f"Open pull request queue is clear ({len(prs)} valid hold(s)).")
PY
