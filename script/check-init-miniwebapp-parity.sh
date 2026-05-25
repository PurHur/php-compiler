#!/usr/bin/env bash
# Keep templates/init-miniwebapp/ byte-identical to examples/003-MiniWebApp on key app files (issue #695).
# Intentional divergence: add the same marker comment in BOTH trees:
#   // miniwebapp-parity: intentional divergence — reason
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_ROOT"

CANONICAL="examples/003-MiniWebApp"
TEMPLATE="templates/init-miniwebapp"

PARITY_FILES=(
  public/index.php
  src/Router.php
  phpc.json
  config.php
  templates/layout.php
  templates/home.php
  templates/hello.php
  templates/contact.php
  templates/thankyou.php
  assets/style.css
)

fail=0

for rel in "${PARITY_FILES[@]}"; do
  left="${CANONICAL}/${rel}"
  right="${TEMPLATE}/${rel}"
  if [[ ! -f "$left" ]]; then
    echo "check-init-miniwebapp-parity: missing canonical file: ${left}" >&2
    fail=1
    continue
  fi
  if [[ ! -f "$right" ]]; then
    echo "check-init-miniwebapp-parity: missing template file: ${right}" >&2
    fail=1
    continue
  fi
  if ! cmp -s "$left" "$right"; then
    echo "check-init-miniwebapp-parity: drift in ${rel} (sync from ${CANONICAL} → ${TEMPLATE}):" >&2
    diff -u "$left" "$right" >&2 || true
    fail=1
  fi
done

TEMPLATE_README="${TEMPLATE}/README.md"
if [[ ! -f "$TEMPLATE_README" ]]; then
  echo "check-init-miniwebapp-parity: missing template README: ${TEMPLATE_README}" >&2
  fail=1
else
  stale_patterns=(
    'tracked in #764'
    'tracked in [#764]'
    'blocked #764'
    'empty stdout until'
  )
  for pattern in "${stale_patterns[@]}"; do
    if grep -qF "$pattern" "$TEMPLATE_README"; then
      echo "check-init-miniwebapp-parity: stale execute wording in templates/init-miniwebapp/README.md: \"${pattern}\" (sync post-#764 — issue #1778)" >&2
      fail=1
    fi
  done
  if ! grep -qE 'AOT execute.*✅|native execute ✅|execute ✅' "$TEMPLATE_README"; then
    echo "check-init-miniwebapp-parity: templates/init-miniwebapp/README.md missing green AOT execute status (issue #1778)" >&2
    fail=1
  fi
  if ! grep -q '#764.*closed' "$TEMPLATE_README"; then
    echo "check-init-miniwebapp-parity: templates/init-miniwebapp/README.md should reference #764 as closed (issue #1778)" >&2
    fail=1
  fi
fi

if [[ "$fail" -ne 0 ]]; then
  echo "check-init-miniwebapp-parity: FAILED — copy key files from ${CANONICAL}/ to ${TEMPLATE}/ (see README sync policy)." >&2
  exit 1
fi

echo "check-init-miniwebapp-parity: OK (${#PARITY_FILES[@]} files in sync with ${CANONICAL})."
