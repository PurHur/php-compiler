#!/usr/bin/env bash
# Fast bootstrap gate subset for self-host iteration (issue #1961).
#
# Wraps inventory + spine-count sync + optional VM smoke (no full ci-local / PHPUnit).
#
# Usage:
#   ./script/bootstrap-test-subset.sh
#   ./script/bootstrap-test-subset.sh --strict   # M3 HelloWorld strict probe when LLVM ready
#   phpc test --bootstrap
#   phpc test --bootstrap --strict
#
# Strict sets BOOTSTRAP_M3_HELLOWORLD_STRICT_GATE=1 for the LLVM tail when not already set.
set -euo pipefail

# shellcheck source=ci-common.sh
source "$(dirname "$0")/ci-common.sh"

STRICT=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --strict) STRICT=1; shift ;;
    -h|--help)
      cat <<'EOF' >&2
Usage: script/bootstrap-test-subset.sh [--strict]

  Inventory freshness, self-host spine count sync, optional M2 VM spine smoke (LLVM).
  --strict  runs bootstrap-selfhost-helloworld-probe.sh when LLVM 9 is available
            (BOOTSTRAP_M3_HELLOWORLD_STRICT_GATE=1 for this invocation).
EOF
      exit 0
      ;;
    *) echo "bootstrap-test-subset: unknown argument: $1" >&2; exit 1 ;;
  esac
done

ci_cd_repo
ci_prepare_test_runtime
ci_install_deps

# Fast subset: inventory + spine sync only unless BOOTSTRAP_TEST_SUBSET_VM_SMOKE=1.
if [[ "${BOOTSTRAP_TEST_SUBSET_VM_SMOKE:-0}" == "1" ]]; then
  export BOOTSTRAP_LIB_SPINE_VM_SMOKE_GATE=1
else
  export BOOTSTRAP_LIB_SPINE_VM_SMOKE_GATE=0
fi

echo "bootstrap-test-subset: inventory --check..."
ci_ensure_generated_doc script/bootstrap-inventory.php docs/bootstrap-inventory.md

ci_run_selfhost_spine_count_sync_check

if ! ci_llvm_ready; then
  echo "bootstrap-test-subset: LLVM 9 not available — LLVM tail skipped"
  if [[ "$STRICT" == "1" ]]; then
    echo "bootstrap-test-subset: --strict skipped (LLVM 9 not available; script/install-llvm9.sh)"
  fi
  echo "bootstrap-test-subset: ok"
  exit 0
fi

ci_apply_llvm_memory_env
if [[ "${BOOTSTRAP_LIB_SPINE_VM_SMOKE_GATE}" == "1" ]]; then
  ci_run_bootstrap_lib_spine_vm_smoke
else
  echo "bootstrap-test-subset: M2 VM spine smoke skipped (BOOTSTRAP_TEST_SUBSET_VM_SMOKE=0; export 1 to enable)"
fi
if [[ "$STRICT" == "1" ]]; then
  export BOOTSTRAP_M3_HELLOWORLD_STRICT_GATE=1
  ci_run_bootstrap_m3_strict
fi

echo "bootstrap-test-subset: ok"
