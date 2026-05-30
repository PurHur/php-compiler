#!/usr/bin/env bash
# CI guard: retired *_m3_emit_native_entry.php must not reappear (issue #3032).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT}"

if [[ "${BOOTSTRAP_NO_M3_EMIT_HELPER_GATE:-1}" != "1" ]]; then
  echo "bootstrap-guard-no-m3-emit-helper: skipped (BOOTSTRAP_NO_M3_EMIT_HELPER_GATE=0)"
  exit 0
fi

if find test -name '*m3_emit_native_entry*' -print -quit 2>/dev/null | grep -q .; then
  echo "bootstrap-guard-no-m3-emit-helper: found *_m3_emit_native_entry.php under test/" >&2
  find test -name '*m3_emit_native_entry*' -print >&2
  exit 1
fi

hits="$(grep -rE --include='*.php' --include='*.sh' 'bootstrap-aot/[a-z_]*m3_emit_native_entry\.php' test script bin 2>/dev/null || true)"
if [[ -n "${hits}" ]]; then
  echo "bootstrap-guard-no-m3-emit-helper: found bootstrap-aot m3_emit_native_entry path references:" >&2
  printf '%s\n' "${hits}" >&2
  exit 1
fi

echo "bootstrap-guard-no-m3-emit-helper: OK (no *_m3_emit_native_entry.php under test/, no bootstrap-aot paths in scripts)"
