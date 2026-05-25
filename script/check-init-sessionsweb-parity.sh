#!/usr/bin/env bash
# Keep templates/init-sessionsweb/ byte-identical to examples/005-SessionsWeb on key app files (issue #1902).
# Intentional divergence: add the same marker comment in BOTH trees:
#   // sessionsweb-parity: intentional divergence — reason
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_ROOT"

CANONICAL="examples/005-SessionsWeb"
TEMPLATE="templates/init-sessionsweb"

# Expand when #1881 adds public/, src/, templates/ — mirror check-init-miniwebapp-parity.sh.
PARITY_FILES=(
  example.php
  phpc.json
)

fail=0

for rel in "${PARITY_FILES[@]}"; do
  left="${CANONICAL}/${rel}"
  right="${TEMPLATE}/${rel}"
  if [[ ! -f "$left" ]]; then
    echo "check-init-sessionsweb-parity: missing canonical file: ${left}" >&2
    fail=1
    continue
  fi
  if [[ ! -f "$right" ]]; then
    echo "check-init-sessionsweb-parity: missing template file: ${right}" >&2
    fail=1
    continue
  fi
  if ! cmp -s "$left" "$right"; then
    echo "check-init-sessionsweb-parity: drift in ${rel} (sync from ${CANONICAL} → ${TEMPLATE}):" >&2
    diff -u "$left" "$right" >&2 || true
    fail=1
  fi
done

TEMPLATE_README="${TEMPLATE}/README.md"
if [[ ! -f "$TEMPLATE_README" ]]; then
  echo "check-init-sessionsweb-parity: missing template README: ${TEMPLATE_README}" >&2
  fail=1
else
  if ! grep -qF 'check-init-sessionsweb-parity.sh' "$TEMPLATE_README"; then
    echo "check-init-sessionsweb-parity: templates/init-sessionsweb/README.md should document ./script/check-init-sessionsweb-parity.sh (issue #1902)" >&2
    fail=1
  fi
  if ! grep -q '#695' "$TEMPLATE_README"; then
    echo "check-init-sessionsweb-parity: templates/init-sessionsweb/README.md should reference sync policy #695 (issue #1902)" >&2
    fail=1
  fi
fi

if [[ "$fail" -ne 0 ]]; then
  echo "check-init-sessionsweb-parity: FAILED — copy key files from ${CANONICAL}/ to ${TEMPLATE}/ (see README sync policy)." >&2
  exit 1
fi

echo "check-init-sessionsweb-parity: OK (${#PARITY_FILES[@]} files in sync with ${CANONICAL})."
