#!/usr/bin/env bash
# Verify docs/adr/README.md indexes every on-disk ADR and required settled/DECISION
# files exist (#36402). Empty index / missing files must not read as green.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
ADR_DIR="$ROOT/docs/adr"
INDEX="$ADR_DIR/README.md"

if [[ ! -f "$INDEX" ]]; then
  echo "check-adr-index: FAIL missing $INDEX" >&2
  exit 1
fi

required=(
  0001-value-representation.md
  0002-memory-model.md
  0003-per-unit-translation-units.md
  0004-extension-boundary.md
  0005-differential-oracle.md
  0006-generated-docs-only.md
  0007-gen0-release-artifact.md
  0008-llvm9-to-22.md
  0009-vendored-forks.md
  0010-fleet-definition-of-done.md
  0011-jit-tier-future.md
  0012-supported-extension-set-v2.md
  36384-php-83-baseline.md
  36393-selfhost-user-payoff.md
)

fail=0
for f in "${required[@]}"; do
  if [[ ! -f "$ADR_DIR/$f" ]]; then
    echo "check-adr-index: FAIL missing required ADR $f" >&2
    fail=1
  fi
done

# Every *.md except README must be linked from the index (basename appears).
while IFS= read -r -d '' path; do
  base="$(basename "$path")"
  [[ "$base" == "README.md" ]] && continue
  if ! grep -Fq "$base" "$INDEX"; then
    echo "check-adr-index: FAIL $base not listed in docs/adr/README.md" >&2
    fail=1
  fi
done < <(find "$ADR_DIR" -maxdepth 1 -type f -name '*.md' -print0 | sort -z)

if [[ "$fail" -ne 0 ]]; then
  exit 1
fi
echo "check-adr-index: OK ($(find "$ADR_DIR" -maxdepth 1 -type f -name '*.md' ! -name README.md | wc -l) ADRs indexed)"
