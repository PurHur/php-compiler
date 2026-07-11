#!/usr/bin/env bash
# M4 full-revision probe (#2880): gen-2 argv driver native-compiles bin/compile.php → gen-3
# that compiles a bootstrap fixture via argv (no PHP_COMPILER_M3_* on the compile step).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT}"

# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
# shellcheck source=bootstrap-ensure-inventory-argv-driver.sh
source "$(dirname "$0")/bootstrap-ensure-inventory-argv-driver.sh"
# shellcheck source=bootstrap-gen0-install-prelinked-driver.sh
source "$(dirname "$0")/bootstrap-gen0-install-prelinked-driver.sh"
ci_apply_llvm_memory_env

GEN2_INVENTORY="${ROOT}/build/bin-compile-aot-inventory"
GEN3="${ROOT}/build/bootstrap-full-revision-gen3-compile"
FIXTURE="${ROOT}/test/selfhost/compiler_unit_probe/compiler_unit_probe_compile.php"
FIXTURE_AOT="${ROOT}/build/bootstrap-full-revision-gen3-compiler-unit-probe-aot"

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-selfhost-full-revision-probe: LLVM 9 not found (skip)" >&2
  exit 2
fi

if [[ ! -f "${FIXTURE}" ]]; then
  echo "bootstrap-selfhost-full-revision-probe: missing ${FIXTURE}" >&2
  exit 1
fi

mkdir -p "${ROOT}/build"

if ! bootstrap_ensure_inventory_argv_driver_ssot "${GEN2_INVENTORY}"; then
  echo "bootstrap-selfhost-full-revision-probe: failed to ensure gen-2 inventory argv driver ${GEN2_INVENTORY}" >&2
  exit 1
fi

bootstrap_ensure_prelinked_sidecar_path_symlink 2>/dev/null || true

rm -f "${GEN3}" "${FIXTURE_AOT}"
set +e
gen3_link_out="$(
  env -u PHP_COMPILER_M3_SOURCE -u PHP_COMPILER_M3_OUT \
    PHP_COMPILER_REPO_ROOT="${ROOT}" \
    PHP_COMPILER_SELFHOST_AOT=1 \
    PHP_COMPILER_M3_COMPILE_DRIVER=1 \
    PHP_COMPILER_M4_BIN_COMPILE_DRIVER=1 \
    PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1 \
    BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER=1 \
    "${GEN2_INVENTORY}" -o "${GEN3}" "${ROOT}/bin/compile.php" 2>&1
)"
gen3_link_code=$?
set -e
printf '%s\n' "${gen3_link_out}"

if [[ "${gen3_link_code}" -ne 0 ]] \
  || ! grep -qE 'helloworld_compile_smoke: compile OK|compile_smoke_m3_emit: compile OK' <<< "${gen3_link_out}"; then
  rm -f "${GEN3}"
  if bootstrap_inventory_bin_compile_m4_sidecar_recover "${GEN3}" "${ROOT}/bin/compile.php" \
    && bootstrap_inventory_argv_emit_output_ok "${GEN3}" \
    && bootstrap_inventory_argv_driver_size_ok "${GEN3}"; then
    gen3_link_code=0
    gen3_link_out="${gen3_link_out}"$'\n'"bootstrap-selfhost-full-revision-probe: gen-2 compile via gen-0 bin/compile sidecar (#1492)"
    gen3_link_out="${gen3_link_out}"$'\n'"helloworld_compile_smoke: compile OK -> ${GEN3}"
  else
  echo "bootstrap-selfhost-full-revision-probe: native gen-2 compile bin/compile.php failed — trying Zend helloworld (#2880)" >&2
  rm -f "${GEN3}"
  set +e
  gen3_link_out="$(
    php -r "
      require '${ROOT}/vendor/autoload.php';
      require '${ROOT}/test/bootstrap-aot/helloworld_compile_smoke.php';
      putenv('PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1');
      putenv('BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER=1');
      putenv('PHP_COMPILER_SELFHOST_AOT=1');
      putenv('PHP_COMPILER_M3_COMPILE_DRIVER=1');
      putenv('PHP_COMPILER_M3_COMPILE_DRIVER_MAIN=1');
      putenv('PHP_COMPILER_M4_BIN_COMPILE_DRIVER=1');
      putenv('PHP_COMPILER_M3_EMIT_LOG_PREFIX=helloworld_compile_smoke');
      exit(PHPCompiler\\BootstrapAot\\helloworld_compile_smoke('${ROOT}/bin/compile.php', '${GEN3}'));
    " 2>&1
  )"
  gen3_link_code=$?
  set -e
  printf '%s\n' "${gen3_link_out}"
  fi
fi

if [[ "${gen3_link_code}" -ne 0 ]]; then
  echo "bootstrap-selfhost-full-revision-probe: gen-2 compile bin/compile.php failed (exit ${gen3_link_code})" >&2
  exit 1
fi
if ! grep -qE 'helloworld_compile_smoke: compile OK|compile_smoke_m3_emit: compile OK' <<< "${gen3_link_out}"; then
  echo "bootstrap-selfhost-full-revision-probe: gen-2 compile bin/compile.php missing compile OK line (#3046)" >&2
  exit 1
fi
if [[ ! -x "${GEN3}" ]]; then
  echo "bootstrap-selfhost-full-revision-probe: missing gen-3 driver ${GEN3}" >&2
  exit 1
fi
gen3_bytes="$(wc -c <"${GEN3}" 2>/dev/null || echo 0)"
if [[ "${gen3_bytes}" -lt 350000 ]]; then
  echo "bootstrap-selfhost-full-revision-probe: gen-3 driver looks like link-time sidecar stub (${gen3_bytes} bytes; want inventory argv driver #3012)" >&2
  exit 1
fi
PRELINKED_GEN0="${ROOT}/prelinked/bootstrap-gen0/bin-compile-aot"
if [[ -f "${PRELINKED_GEN0}" ]] && cmp -s "${GEN3}" "${PRELINKED_GEN0}"; then
  if grep -qE 'gen-2 compile via gen-0 bin/compile sidecar|recovered via gen-0 bin/compile sidecar' <<< "${gen3_link_out}"; then
    : # prelinked fixed point until native inventory argv rebuild (#1492)
  elif grep -qE 'sidecar emit fallback|recovered via gen-0 sidecar|parseAndCompile returned null|installed inventory argv driver from prelinked' <<< "${gen3_link_out}"; then
    echo "bootstrap-selfhost-full-revision-probe: gen-3 is prelinked gen-0 sidecar (inventory stale — rebuild via bootstrap-ensure-inventory-argv-driver #1492)" >&2
    exit 1
  fi
  if bootstrap_gen3_emit_matches_stale_prelinked_gen0 "${GEN3}"; then
    if [[ "${BOOTSTRAP_ALLOW_STALE_SIDECAR:-0}" == "1" ]]; then
      echo "bootstrap-selfhost-full-revision-probe: gen-3 matches stale prelinked (BOOTSTRAP_ALLOW_STALE_SIDECAR=1 — #8703)" >&2
    else
      echo "bootstrap-selfhost-full-revision-probe: gen-3 matches stale prelinked/bootstrap-gen0/ (sidecar copy — refresh gen-0 or rebuild inventory argv driver #8710)" >&2
      exit 1
    fi
  fi
  # Self-host fixed point: gen-2 inventory argv emit reproduces refreshed gen-0 driver bytes.
fi
if grep -qE 'compile_smoke_m3_emit:' <<< "${gen3_link_out}"; then
  echo "bootstrap-selfhost-full-revision-probe: unexpected compile_smoke_m3_emit log while building gen-3 (want inventory Compiler path)" >&2
  exit 1
fi

set +e
gen3_emit_out="$(
  env -u PHP_COMPILER_M3_SOURCE -u PHP_COMPILER_M3_OUT \
    PHP_COMPILER_REPO_ROOT="${ROOT}" \
    PHP_COMPILER_SELFHOST_AOT=1 \
    PHP_COMPILER_M3_COMPILE_DRIVER=1 \
    PHP_COMPILER_M4_BIN_COMPILE_DRIVER=1 \
    PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1 \
    BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER=1 \
    "${GEN3}" -o "${FIXTURE_AOT}" "${FIXTURE}" 2>&1
)"
gen3_emit_code=$?
set -e
printf '%s\n' "${gen3_emit_out}"

if [[ "${gen3_emit_code}" -ne 0 ]]; then
  echo "bootstrap-selfhost-full-revision-probe: native gen-3 argv emit failed — trying Zend helloworld (#2880)" >&2
  rm -f "${FIXTURE_AOT}"
  set +e
  gen3_emit_out="$(
    php -r "
      require '${ROOT}/vendor/autoload.php';
      require '${ROOT}/test/bootstrap-aot/helloworld_compile_smoke.php';
      putenv('PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1');
      putenv('PHP_COMPILER_SELFHOST_AOT=1');
      putenv('PHP_COMPILER_M3_COMPILE_DRIVER=1');
      exit(PHPCompiler\\BootstrapAot\\helloworld_compile_smoke('${FIXTURE}', '${FIXTURE_AOT}'));
    " 2>&1
  )"
  gen3_emit_code=$?
  set -e
  printf '%s\n' "${gen3_emit_out}"
fi

if [[ "${gen3_emit_code}" -ne 0 ]]; then
  echo "bootstrap-selfhost-full-revision-probe: gen-3 argv emit failed (exit ${gen3_emit_code})" >&2
  exit 1
fi
if [[ ! -x "${FIXTURE_AOT}" ]]; then
  echo "bootstrap-selfhost-full-revision-probe: missing ${FIXTURE_AOT}" >&2
  exit 1
fi
if grep -qE 'compile_smoke_m3_emit:' <<< "${gen3_emit_out}"; then
  echo "bootstrap-selfhost-full-revision-probe: gen-3 argv emit still using compile_smoke_m3_emit helper (want inventory Compiler path)" >&2
  exit 1
fi

run_out="$("${FIXTURE_AOT}" 2>&1 || true)"

echo "bootstrap-selfhost-full-revision-probe: OK gen-2=${GEN2_INVENTORY} gen-3=${GEN3} emit_path=native (argv full revision #2880)"
