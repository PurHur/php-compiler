#!/usr/bin/env bash
# Keep templates/init-fastcgiweb/ byte-identical to examples/009-FastCGIWeb on key app files (issue #2342).
# Intentional divergence: add the same marker comment in BOTH trees:
#   // fastcgiweb-parity: intentional divergence — reason
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_ROOT"

CANONICAL="examples/009-FastCGIWeb"
TEMPLATE="templates/init-fastcgiweb"

PARITY_FILES=(
  example.php
  phpc.json
)

fail=0

for rel in "${PARITY_FILES[@]}"; do
  left="${CANONICAL}/${rel}"
  right="${TEMPLATE}/${rel}"
  if [[ ! -f "$left" ]]; then
    echo "check-init-fastcgiweb-parity: missing canonical file: ${left}" >&2
    fail=1
    continue
  fi
  if [[ ! -f "$right" ]]; then
    echo "check-init-fastcgiweb-parity: missing template file: ${right}" >&2
    fail=1
    continue
  fi
  if ! cmp -s "$left" "$right"; then
    echo "check-init-fastcgiweb-parity: drift in ${rel} (sync from ${CANONICAL} → ${TEMPLATE}):" >&2
    diff -u "$left" "$right" >&2 || true
    fail=1
  fi
done

TEMPLATE_README="${TEMPLATE}/README.md"
if [[ ! -f "$TEMPLATE_README" ]]; then
  echo "check-init-fastcgiweb-parity: missing template README: ${TEMPLATE_README}" >&2
  fail=1
else
  if ! grep -qF 'check-init-fastcgiweb-parity.sh' "$TEMPLATE_README"; then
    echo "check-init-fastcgiweb-parity: templates/init-fastcgiweb/README.md should document ./script/check-init-fastcgiweb-parity.sh (issue #2342)" >&2
    fail=1
  fi
  if ! grep -q '#695' "$TEMPLATE_README"; then
    echo "check-init-fastcgiweb-parity: templates/init-fastcgiweb/README.md should reference sync policy #695 (issue #2342)" >&2
    fail=1
  fi
fi

if [[ "$fail" -ne 0 ]]; then
  echo "check-init-fastcgiweb-parity: FAILED — copy key files from ${CANONICAL}/ to ${TEMPLATE}/ (see README sync policy)." >&2
  exit 1
fi

echo "check-init-fastcgiweb-parity: OK (${#PARITY_FILES[@]} files in sync with ${CANONICAL})."
