#!/usr/bin/env bash
# Non-blocking bootstrap honesty warnings for fast dev gates (#36145, #22642).
#
# A committed gen-0 stamp can disagree with live lowering sources without
# breaking aot-smoke / differential — but strict emit and native bootstrap
# gates will fail until sidecars are refreshed.
#
# Usage:
#   ./script/bootstrap-trust-preflight.sh          # warn, exit 0
#   BOOTSTRAP_TRUST_PREFLIGHT_STRICT=1 ...         # exit 1 when stale
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
export ROOT
# shellcheck source=bootstrap-lowering-freshness.sh
source "${ROOT}/script/bootstrap-lowering-freshness.sh"

warn=0

# Git-derived seed age (#22642) — does not trust manifest stamps.
set +e
staleness_out="$(php "${ROOT}/script/bootstrap-gen0-staleness.php" 2>&1)"
staleness_code=$?
set -e
if [[ "${staleness_code}" -ne 0 ]]; then
  echo "bootstrap-trust-preflight: WARNING — ${staleness_out#bootstrap-gen0-staleness: }" >&2
  warn=1
else
  echo "bootstrap-trust-preflight: gen-0 seed age OK — ${staleness_out#bootstrap-gen0-staleness: }"
fi

# Lowering fingerprint vs prelinked stamp (#21855).
want=""
if want="$(bootstrap_lowering_source_fingerprint 2>/dev/null)"; then
  prelinked="$(bootstrap_lowering_source_prelinked_stamp)"
  have=""
  if [[ -f "${prelinked}" ]]; then
    have="$(tr -d '\n' <"${prelinked}")"
  fi
  if [[ -n "${have}" && "${want}" != "${have}" ]]; then
    echo "bootstrap-trust-preflight: WARNING — lowering fingerprint drift (live ${want:0:12}…, prelinked ${have:0:12}…)" >&2
    echo "bootstrap-trust-preflight: native bootstrap / north-star5 --strict will refuse stale sidecars (#21855)" >&2
    warn=1
  fi
fi

# Manifest sync warnings (#21905) — restamp without rebuild, provenance drift, etc.
while IFS= read -r line; do
  if [[ "${line}" == *"WARNING"* ]]; then
    echo "bootstrap-trust-preflight: ${line#check-bootstrap-gen0-manifest-sync: }" >&2
    warn=1
  fi
done < <(php "${ROOT}/script/check-bootstrap-gen0-manifest-sync.php" 2>&1 | grep -F 'WARNING' || true)

if [[ "${warn}" -eq 1 ]]; then
  echo "bootstrap-trust-preflight: refresh — PHP_COMPILER_DOCKER_MEM=16g PHP_COMPILER_DOCKER_MEM_SWAP=32g ./script/bootstrap-gen0-refresh-argv-driver.sh" >&2
  echo "bootstrap-trust-preflight: or script/bootstrap-refresh-gen0-sidecar.sh (#36145)" >&2
  if [[ "${BOOTSTRAP_TRUST_PREFLIGHT_STRICT:-0}" == "1" ]]; then
    exit 1
  fi
  echo "bootstrap-trust-preflight: continuing (non-blocking for dev-verify-fast)"
  exit 0
fi

echo "bootstrap-trust-preflight: OK"
exit 0
