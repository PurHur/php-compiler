#!/usr/bin/env bash
# Bootstrap SDK init — seed gen-0 prelink without full dev harness (issue #15600).
#
# Tier 1 path: prelinked gen-0 + optional vendor prelink; Composer only when --with-composer.
#
# Usage:
#   ./script/bootstrap-init.sh
#   ./script/bootstrap-init.sh --with-composer   # also composer install + apply-patches (Tier 0)
#   ./script/bootstrap-init.sh --sdk-url URL     # fetch Bootstrap SDK tarball first (#15602)
#   phpc bootstrap init [--with-composer] [--sdk-url URL]
#   PHP_COMPILER_BOOTSTRAP_SDK=URL phpc bootstrap init
#
# See docs/bootstrap-dev-workflow.md
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT}"

WITH_COMPOSER=0
SKIP_VERIFY=0
SDK_URL="${PHP_COMPILER_BOOTSTRAP_SDK:-}"
while [[ $# -gt 0 ]]; do
  case "$1" in
    --with-composer) WITH_COMPOSER=1; shift ;;
    --skip-verify) SKIP_VERIFY=1; shift ;;
    --sdk-url)
      if [[ $# -lt 2 ]]; then
        echo "bootstrap-init: --sdk-url requires a value" >&2
        exit 1
      fi
      SDK_URL="$2"
      shift 2
      ;;
    -h|--help)
      cat <<'EOF'
Usage: script/bootstrap-init.sh [--with-composer] [--skip-verify] [--sdk-url URL]

Bootstrap SDK cold start for gen-1+ development:
  0. Optional: fetch Bootstrap SDK tarball (--sdk-url or PHP_COMPILER_BOOTSTRAP_SDK)
  1. Verify prelinked/bootstrap-gen0/ seed exists
  2. BOOTSTRAP_M5_NO_ZEND=1 make bootstrap-selfhost-link (compiler_minimal)
  3. Optional: composer install + apply-patches (--with-composer, Tier 0 harness)
  4. north-star5-verify-fast when LLVM present

Options:
  --with-composer  Run composer install + script/apply-patches.sh (PHPUnit / ci-fast path)
  --skip-verify    Skip north-star5-verify-fast tail
  --sdk-url URL    Download/extract Bootstrap SDK tarball (http(s), file://, or local path)

Environment:
  PHP_COMPILER_BOOTSTRAP_SDK  Default URL when --sdk-url omitted

Next: docs/bootstrap-dev-workflow.md · phpc doctor --selfhost
EOF
      exit 0
      ;;
    *)
      echo "bootstrap-init: unknown argument: $1" >&2
      exit 1
      ;;
  esac
done

# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
# shellcheck source=selfhost-preflight.sh
source "$(dirname "$0")/selfhost-preflight.sh"

FETCH_SCRIPT="${ROOT}/script/bootstrap-sdk-fetch.sh"
if [[ -n "${SDK_URL}" ]]; then
  if [[ ! -x "${FETCH_SCRIPT}" ]]; then
    echo "bootstrap-init: ${FETCH_SCRIPT} is not executable" >&2
    exit 1
  fi
  echo "==> bootstrap-init: fetch Bootstrap SDK"
  "${FETCH_SCRIPT}" "${SDK_URL}"
fi

PRELINKED_DRIVER="${ROOT}/prelinked/bootstrap-gen0/bin-compile-aot"
PRELINKED_STAMP="${ROOT}/prelinked/bootstrap-gen0/.m3_compiler_lib_sidecar.sha"

if [[ ! -x "${PRELINKED_DRIVER}" ]]; then
  echo "bootstrap-init: missing ${PRELINKED_DRIVER}" >&2
  echo "bootstrap-init: clone a release tag, or: phpc bootstrap init --sdk-url URL (#15602)" >&2
  exit 1
fi
if [[ ! -f "${PRELINKED_STAMP}" ]]; then
  echo "bootstrap-init: missing ${PRELINKED_STAMP}" >&2
  exit 1
fi

echo "==> bootstrap-init: gen-0 prelink OK ($(wc -c <"${PRELINKED_DRIVER}") bytes driver)"

if [[ "${WITH_COMPOSER}" == "1" ]]; then
  if ! command -v composer >/dev/null 2>&1; then
    echo "bootstrap-init: composer not found (--with-composer requires Composer)" >&2
    exit 1
  fi
  echo "==> bootstrap-init: composer install + apply-patches (Tier 0 harness)"
  composer install --no-interaction --ignore-platform-reqs -q
  script/apply-patches.sh
else
  echo "bootstrap-init: skipping composer (Tier 1 only; use --with-composer for PHPUnit/ci-fast)"
fi

ci_apply_llvm_memory_env

echo "==> bootstrap-init: M0 link (BOOTSTRAP_M5_NO_ZEND=1)"
BOOTSTRAP_M5_NO_ZEND=1 make -C "${ROOT}" bootstrap-selfhost-link

if [[ -x "${ROOT}/build/selfhost" ]]; then
  "${ROOT}/build/selfhost" || true
fi

if [[ "${SKIP_VERIFY}" == "0" ]]; then
  if [[ -n "${PHP_COMPILER_LLVM_PATH:-}" && -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
    echo "==> bootstrap-init: north-star5-verify-fast"
    make -C "${ROOT}" north-star5-verify-fast
  else
    echo "bootstrap-init: LLVM 9 not found — skip north-star5-verify-fast (install via script/install-llvm9.sh or Docker)"
  fi
fi

echo ""
echo "bootstrap-init: OK — Tier 1 ready"
echo "  Daily compile: build/bin-compile-aot-inventory -o OUT SOURCE.php"
echo "  Doctor:        ./phpc doctor --selfhost"
echo "  Docs:          docs/bootstrap-dev-workflow.md"
echo "  Epic:          #1492 · #15600"
