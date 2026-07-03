#!/usr/bin/env bash
# Detect gen-0 sidecar recovery in bootstrap compile logs (issue #15603).
#
# Usage (source only):
#   source script/bootstrap-honest-compile-lib.sh
#   bootstrap_honest_compile_log_uses_sidecar_recovery "$log_text" && echo sidecar
#   bootstrap_honest_compile_gate_check "$log_text"   # exits 1 when gate on + recovery
#
# Env:
#   BOOTSTRAP_HONEST_COMPILE_GATE=1  fail when recovery patterns present
set -euo pipefail

bootstrap_honest_compile_recovery_patterns() {
  cat <<'PATTERNS'
recovered via gen-0 sidecar
sidecar emit fallback
gen-0 sidecar emit fallback
recovered via gen-0 bin/compile sidecar
m4 argv compile via gen-0 bin/compile sidecar
installed inventory argv driver from prelinked
PATTERNS
}

# Returns 0 when log indicates sidecar / Zend recovery (not honest native compile).
bootstrap_honest_compile_log_uses_sidecar_recovery() {
  local log="${1:-}"
  [[ -n "${log}" ]] || return 1
  local pattern
  while IFS= read -r pattern; do
    [[ -n "${pattern}" ]] || continue
    if grep -qF "${pattern}" <<<"${log}"; then
      return 0
    fi
  done < <(bootstrap_honest_compile_recovery_patterns)
  if grep -qE 'parseAndCompile returned null.*spine|helloworld_compile_smoke: native emit failed at phase=parseAndCompile' <<<"${log}"; then
    if grep -qE 'sidecar|recovered via' <<<"${log}"; then
      return 0
    fi
  fi
  return 1
}

bootstrap_honest_compile_gate_enabled() {
  [[ "${BOOTSTRAP_HONEST_COMPILE_GATE:-0}" == "1" ]]
}

# Exit 1 when honest gate enabled and log shows recovery. Prints reason to stderr.
bootstrap_honest_compile_gate_check() {
  local log="${1:-}"
  local label="${2:-compile}"
  if ! bootstrap_honest_compile_gate_enabled; then
    return 0
  fi
  if ! bootstrap_honest_compile_log_uses_sidecar_recovery "${log}"; then
    echo "bootstrap-honest-compile: OK (${label} — no sidecar recovery detected)" >&2
    return 0
  fi
  echo "bootstrap-honest-compile: FAILED (${label}) — gen-0 sidecar recovery detected (BOOTSTRAP_HONEST_COMPILE_GATE=1, #15603)" >&2
  echo "bootstrap-honest-compile: honest native compile required for gen-1+ release; see docs/bootstrap-dev-workflow.md" >&2
  grep -E 'sidecar|recovered via|parseAndCompile returned null' <<<"${log}" | head -n 8 >&2 || true
  return 1
}
