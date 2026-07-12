#!/usr/bin/env bash
# Seconds-fast generated-doc drift gate (#15621).
#
# Bundles only the drift checks — no PHPUnit, no linking — so every PR
# (including docs-only and advertisement-gate PRs) can run it pre-merge:
#
#   ./script/check-generated-docs.sh            # host PHP
#   ./script/docker-exec.sh -- bash -lc './script/check-generated-docs.sh'
#
# Exit 1 on any drift. Target wall time < 30 s.
set -uo pipefail
cd "$(dirname "$0")/.."

PHP_BIN="${PHP_BIN:-php}"
fail=0

step() {
  local name="$1"; shift
  if "$@"; then
    echo "check-generated-docs: OK   $name"
  else
    echo "check-generated-docs: FAIL $name" >&2
    fail=1
  fi
}

# 1. Capability matrix (docs/capabilities.md) — #15619 class of drift
step "capability-matrix --check" "$PHP_BIN" script/capability-matrix.php --check

# 2. Capability syntax page (docs/capabilities-syntax.md)
step "capability-syntax --check" "$PHP_BIN" script/capability-syntax.php --check

# 3. Bootstrap inventory headers (docs/bootstrap-inventory.md)
step "bootstrap-inventory --check" "$PHP_BIN" script/bootstrap-inventory.php --check

# 4. Bootstrap SDK platform contract (docs/bootstrap-sdk-platform.{md,json}) — #15606
step "bootstrap-sdk-platform --check" "$PHP_BIN" script/check-bootstrap-sdk-platform.php

# 5. composer.lock content-hash vs composer.json — #15620 class of drift
step "composer.lock content-hash" "$PHP_BIN" script/check-composer-lock-hash.php

if [[ "$fail" -ne 0 ]]; then
  echo "check-generated-docs: drift detected — regenerate in the pinned env (see CONTRIBUTING 'Generated docs')" >&2
  exit 1
fi
echo "check-generated-docs: all generated docs in sync."
