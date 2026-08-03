#!/usr/bin/env bash
# M3 HelloWorld self-host probe (issue #1056): link selfhost bundle, native or Zend emit, run natively.
set -euo pipefail
if [[ "${BOOTSTRAP_M3_HELLOWORLD_STRICT:-0}" == "1" ]]; then
  export BOOTSTRAP_M3_LINK_COMPILE_DRIVER="${BOOTSTRAP_M3_LINK_COMPILE_DRIVER:-1}"
  export BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING="${BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING:-1}"
  export BOOTSTRAP_M3_RUNTIME_COMPILE="${BOOTSTRAP_M3_RUNTIME_COMPILE:-1}"
fi
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENTRY="${ROOT}/test/selfhost/compiler_helloworld_smoke/main.php"
PROBE="${ROOT}/build/selfhost-helloworld"
SOURCE="${ROOT}/examples/000-HelloWorld/example.php"
AOT_OUT="${ROOT}/build/helloworld-aot"
EMIT_HELPER="${ROOT}/build/selfhost-helloworld-emit"
COMPILE_DRIVER_BIN="${ROOT}/build/selfhost-helloworld-compile"
INVENTORY_EMIT_DRIVER="${ROOT}/test/selfhost/compiler_helloworld_smoke/compile_driver.php"
# shellcheck source=bootstrap-inventory-emit-default.sh
source "$(dirname "$0")/bootstrap-inventory-emit-default.sh"
bootstrap_resolve_inventory_emit_driver "${INVENTORY_EMIT_DRIVER}"
M3_NATIVE_COMPILE=0
M3_SIDECAR_FALLBACK=0
M3_SIDECAR_REASON=""
M3_EMIT_PATH="none"
M3_EMIT_HELPER_LINKED=0
M3_BLOCK_REASON="native emit helper not linked (set BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1)"

# When inventory compile_driver sidecar is fingerprint-stale, cold IncludeHelper OOMs
# past 24GiB (#23970). Prefer the committed gen-0 argv driver as emit helper — that is
# the honest M3 HelloWorld native emit path (#22178); later argv -o OUT SOURCE must still
# print helloworld_compile_smoke: compile OK (no ready-stub / no HelloWorld blob COPY).
# When helloworld smoke main sidecar is fingerprint-stale, Zend AOT of main.php is a
# multi-GB IncludeHelper (same class as compile_driver — #23970). Prefer the committed
# smoke-main blob after a functional run check (must print bundle OK).
helloworld_try_prelinked_smoke_probe() {
  bootstrap_gen0_seed_prelinked_m3_sidecars 2>/dev/null || true
  local candidate
  for candidate in \
    "${ROOT}/build/.m3_helloworld_smoke_main_aot_blob" \
    "${ROOT}/prelinked/bootstrap-gen0/.m3_helloworld_smoke_main_aot_blob"
  do
    [[ -x "${candidate}" && -s "${candidate}" ]] || continue
    mkdir -p "$(dirname "${PROBE}")"
    if ! cp -f "${candidate}" "${PROBE}"; then
      continue
    fi
    chmod +x "${PROBE}" 2>/dev/null || true
    if [[ -x "${PROBE}" ]] && "${PROBE}" 2>/dev/null | grep -q 'compiler_helloworld_smoke bundle OK'; then
      echo "bootstrap-selfhost-helloworld-probe: using prelinked smoke main as probe (${candidate} -> ${PROBE}; avoid Zend main.php cold AOT — #23970/#9704)" >&2
      return 0
    fi
    rm -f "${PROBE}"
  done
  return 1
}

helloworld_try_gen0_argv_as_emit_helper() {
  bootstrap_gen0_install_prelinked_driver 2>/dev/null || true
  bootstrap_gen0_seed_prelinked_m3_sidecars 2>/dev/null || true
  local candidate
  for candidate in \
    "${ROOT}/build/bin-compile-aot" \
    "${ROOT}/build/.m3_bin_compile_aot_blob" \
    "${ROOT}/prelinked/bootstrap-gen0/bin-compile-aot" \
    "${ROOT}/prelinked/bootstrap-gen0/.m3_bin_compile_aot_blob"
  do
    [[ -x "${candidate}" && -s "${candidate}" ]] || continue
    # Ready-echo stubs ignore argv (#22178 / re-#21860).
    if "${candidate}" 2>/dev/null | grep -q 'compiler_helloworld_compile_driver ready'; then
      continue
    fi
    mkdir -p "$(dirname "${EMIT_HELPER}")"
    if ! cp -f "${candidate}" "${EMIT_HELPER}"; then
      continue
    fi
    chmod +x "${EMIT_HELPER}" 2>/dev/null || true
    if [[ -x "${EMIT_HELPER}" ]]; then
      echo "bootstrap-selfhost-helloworld-probe: using gen-0 argv driver as emit helper (${candidate} -> ${EMIT_HELPER}; avoid cold inventory compile_driver OOM — #23970/#22178)" >&2
      return 0
    fi
  done
  return 1
}

helloworld_m3_emit_next_lower() {
  if [[ "${M3_EMIT_HELPER_LINKED}" -eq 0 ]]; then
    if [[ "${BOOTSTRAP_M3_LINK_COMPILE_DRIVER:-0}" != "1" ]]; then
      echo "bootstrap-selfhost-helloworld-probe: NEXT_LOWER: set BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1 (+ real lowering + runtime compile) before emit-TU execute (#2572)" >&2
    elif grep -qE 'Allowed memory size|memory exhausted' <<< "${M3_BLOCK_REASON}${m3_link_out:-}"; then
      echo "bootstrap-selfhost-helloworld-probe: NEXT_LOWER: cold inventory compile_driver still OOMs (#23970) — ensure prelinked gen-0 argv driver is present for emit-helper fallback (#22178), or refresh .m3_compile_driver_aot_blob / shrink require graph" >&2
    else
      echo "bootstrap-selfhost-helloworld-probe: NEXT_LOWER: M3 emit helper link — ${M3_BLOCK_REASON} (#1768)" >&2
    fi
    echo "bootstrap-selfhost-helloworld-probe: NEXT_LOWER_CMD: PHP_COMPILER_DOCKER_MEM=16g PHP_COMPILER_DOCKER_MEM_SWAP=16g PHP_COMPILER_CI_RAM_GB=0 ./script/docker-exec.sh -- bash -lc 'source script/php-env.sh && BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1 BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING=1 BOOTSTRAP_M3_HELLOWORLD_STRICT=1 ./script/bootstrap-selfhost-helloworld-probe.sh'" >&2
    return
  fi
  if grep -qE 'segfault|SIGKILL|exit 139' <<< "${M3_BLOCK_REASON}"; then
    echo "bootstrap-selfhost-helloworld-probe: NEXT_LOWER: M3 emit TU LLVM 9 link/execute (global ctor / OOM; #2540)" >&2
    return
  fi
  if grep -qE 'phase=parseAndCompile|parseAndCompile returned null' <<< "${M3_BLOCK_REASON}"; then
    echo "bootstrap-selfhost-helloworld-probe: NEXT_LOWER: HelloWorld emit TU parseAndCompile spine (#2567)" >&2
    return
  fi
  if grep -qE 'phase=parse|Runtime::parse returned null' <<< "${M3_BLOCK_REASON}"; then
    echo "bootstrap-selfhost-helloworld-probe: NEXT_LOWER: Runtime::parse / initParsePipeline spine (#2568)" >&2
    return
  fi
  if grep -q 'phase=compileEmitSmoke' <<< "${M3_BLOCK_REASON}"; then
    echo "bootstrap-selfhost-helloworld-probe: NEXT_LOWER: Runtime::compileEmitSmoke / Compiler spine (#2566)" >&2
    return
  fi
  echo "bootstrap-selfhost-helloworld-probe: NEXT_LOWER: M3 emit TU runtime init (global ctor / type reconstructor; #1937)" >&2
}
# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
# shellcheck source=selfhost-preflight.sh
source "$(dirname "$0")/selfhost-preflight.sh"
# shellcheck source=bootstrap-gen0-install-prelinked-driver.sh
source "$(dirname "$0")/bootstrap-gen0-install-prelinked-driver.sh"
# shellcheck source=bootstrap-resolve-compile-invoke.sh
source "$(dirname "$0")/bootstrap-resolve-compile-invoke.sh"
ci_apply_llvm_memory_env
# Inventory compile_driver (#23970): SourceBundler mega-concat OOMs through 24GiB.
# Skip-bundle + HELPER_RUNTIME_O fixes phpc_str_replace, but IncludeHelper of the
# Runtime transitive closure still OOMs at 24GiB (measured). Floor 16GiB for residual
# peak; host: PHP_COMPILER_DOCKER_MEM=16g … PHP_COMPILER_CI_RAM_GB=0.
# Prefer gen-0 argv as emit helper (#22178 / #27509) before compile_driver sidecar;
# only cold-link inventory AOT when both are absent — see helloworld_try_gen0_argv_as_emit_helper.
# shellcheck source=ci-resource-limits.sh
source "$(dirname "$0")/ci-resource-limits.sh"
export PHP_COMPILER_CI_RAM_GB=0
ci_apply_resource_limits 2>/dev/null || true
ulimit -v unlimited 2>/dev/null || ulimit -v 0 2>/dev/null || true
if [[ "${PHP_COMPILER_HELPER_RUNTIME_O:-}" != "1" && "${PHP_COMPILER_HELPER_RUNTIME_O:-}" != "true" ]]; then
  export PHP_COMPILER_HELPER_RUNTIME_O=1
  echo "bootstrap-selfhost-helloworld-probe: enabled PHP_COMPILER_HELPER_RUNTIME_O=1 for skip-bundle compile_driver ABIs (#23970)" >&2
fi
helloworld_mem_mib() {
  local v="${1:-}"
  local n u
  if [[ -z "${v}" ]]; then
    echo 0
    return
  fi
  n="${v%[MmGgKk]}"
  u="${v#"${n}"}"
  case "${u}" in
    G|g) echo $((n * 1024)) ;;
    M|m|'') echo "${n}" ;;
    K|k) echo $((n / 1024)) ;;
    *) echo 0 ;;
  esac
}
HELLOWORLD_EMIT_MEM_FLOOR_MIB=16384
_cur_mib="$(helloworld_mem_mib "${PHP_COMPILER_MEMORY_LIMIT:-}")"
if [[ "${_cur_mib}" -lt "${HELLOWORLD_EMIT_MEM_FLOOR_MIB}" ]]; then
  export PHP_COMPILER_MEMORY_LIMIT=16384M
  echo "bootstrap-selfhost-helloworld-probe: raised PHP_COMPILER_MEMORY_LIMIT to 16384M for inventory emit-helper (#23970; was ${_cur_mib}MiB)" >&2
fi
unset _cur_mib
if [[ -r /sys/fs/cgroup/memory.max ]]; then
  _cgroup_max="$(cat /sys/fs/cgroup/memory.max 2>/dev/null || true)"
  if [[ "${_cgroup_max}" =~ ^[0-9]+$ ]] && [[ "${_cgroup_max}" -lt $((16 * 1024 * 1024 * 1024)) ]]; then
    echo "bootstrap-selfhost-helloworld-probe: WARNING cgroup memory.max=${_cgroup_max} < 16GiB — emit-helper may SIGKILL; re-run docker-exec with PHP_COMPILER_DOCKER_MEM=16g (#23970)" >&2
  fi
  unset _cgroup_max
elif [[ -r /sys/fs/cgroup/memory/memory.limit_in_bytes ]]; then
  _cgroup_max="$(cat /sys/fs/cgroup/memory/memory.limit_in_bytes 2>/dev/null || true)"
  if [[ "${_cgroup_max}" =~ ^[0-9]+$ ]] && [[ "${_cgroup_max}" -lt $((16 * 1024 * 1024 * 1024)) ]]; then
    echo "bootstrap-selfhost-helloworld-probe: WARNING cgroup memory.limit_in_bytes=${_cgroup_max} < 16GiB — emit-helper may SIGKILL; re-run docker-exec with PHP_COMPILER_DOCKER_MEM=16g (#23970)" >&2
  fi
  unset _cgroup_max
fi

selfhost_preflight bootstrap-selfhost-helloworld-probe php-or-docker
selfhost_apply_patches_if_needed

# Strict mode requires native emit — auto-enable compile-driver link env (mirror compile-smoke; #2610).
if [[ "${BOOTSTRAP_M3_HELLOWORLD_STRICT:-0}" == "1" ]]; then
  export BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1
  export BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING=1
  export BOOTSTRAP_M3_RUNTIME_COMPILE="${BOOTSTRAP_M3_RUNTIME_COMPILE:-1}"
  bootstrap_resolve_inventory_emit_driver "${INVENTORY_EMIT_DRIVER}"
fi

m3_exit_label() {
  local code=$1
  if [[ "${code}" -eq 139 ]]; then
    echo "segfault (LLVM 9 emit TU global init; see docs/bootstrap-m5-fast-path.md)"
  elif [[ "${code}" -eq 137 ]]; then
    echo "SIGKILL (likely OOM during link)"
  elif [[ "${code}" -ne 0 ]]; then
    echo "exit ${code}"
  else
    echo "ok"
  fi
}

if [[ ! -f "${ENTRY}" ]]; then
  echo "bootstrap-selfhost-helloworld-probe: missing ${ENTRY}" >&2
  exit 1
fi

if [[ ! -f "${SOURCE}" ]]; then
  echo "bootstrap-selfhost-helloworld-probe: missing ${SOURCE}" >&2
  exit 1
fi

if [[ ! -f "${INVENTORY_EMIT_DRIVER}" ]]; then
  echo "bootstrap-selfhost-helloworld-probe: missing ${INVENTORY_EMIT_DRIVER} (#3032)" >&2
  exit 1
fi

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-selfhost-helloworld-probe: LLVM 9 not found (skip)" >&2
  exit 2
fi

# Default-on native compile-driver when LLVM present (mirror bootstrap-loop-gen1-link; #2620).
: "${BOOTSTRAP_M3_LINK_COMPILE_DRIVER:=1}"
: "${BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING:=1}"
: "${BOOTSTRAP_M3_RUNTIME_COMPILE:=1}"

mkdir -p "${ROOT}/build"
export PHP_COMPILER_SELFHOST_AOT=1
export PHP_COMPILER_JIT_PROGRESS_FILE="${ROOT}/build/.last-jit-func-helloworld-probe"
rm -f "${PROBE}" "${EMIT_HELPER}" "${COMPILE_DRIVER_BIN}" "${AOT_OUT}" "${PHP_COMPILER_JIT_PROGRESS_FILE}"

# Optional (opt-in) diagnostic: also link a native "compile driver" binary.
# This is not required for the M3 HelloWorld acceptance; keep it off by default
# to avoid long/oomy LLVM AOT links in constrained environments.
if [[ "${BOOTSTRAP_M3_BUILD_COMPILE_DRIVER_BIN:-0}" == "1" && "${BOOTSTRAP_M3_LINK_COMPILE_DRIVER:-0}" == "1" && -f "${INVENTORY_EMIT_DRIVER}" ]]; then
  set +e
  echo "bootstrap-selfhost-helloworld-probe: linking helloworld compile driver binary..."
  env PHP_COMPILER_SELFHOST_AOT=1 \
    PHP_COMPILER_M3_COMPILE_DRIVER=1 \
    PHP_COMPILER_EMIT_HELPER_LINK=1 \
    php "${ROOT}/bin/compile.php" -o "${COMPILE_DRIVER_BIN}" "${INVENTORY_EMIT_DRIVER}" >/dev/null 2>&1
  cd_link_code=$?
  set -e
  if [[ -x "${COMPILE_DRIVER_BIN}" ]]; then
    echo "bootstrap-selfhost-helloworld-probe: helloworld compile binary link OK (${COMPILE_DRIVER_BIN}, #2681)"
  else
    echo "bootstrap-selfhost-helloworld-probe: helloworld compile binary link failed (exit ${cd_link_code})" >&2
  fi
fi

if [[ "${BOOTSTRAP_M3_LINK_COMPILE_DRIVER:-0}" == "1" ]]; then
  : "${BOOTSTRAP_M3_RUNTIME_COMPILE:=1}"
  m3_link_env=()
  m3_link_mode="stub"
  if [[ "${BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING:-1}" == "1" ]]; then
    m3_link_env=(env PHP_COMPILER_SELFHOST_AOT=1 PHP_COMPILER_M3_COMPILE_DRIVER=1 PHP_COMPILER_EMIT_HELPER_LINK=1 PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1 BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER=1 PHP_COMPILER_M3_EMIT_LOG_PREFIX=helloworld_compile_smoke)
    m3_link_mode="inventory compile_driver (#3032)"
    m3_emit_source="${INVENTORY_EMIT_DRIVER}"
  else
    m3_link_env=(env PHP_COMPILER_SELFHOST_AOT=1)
    m3_link_mode="selfhost stubs (no PHP_COMPILER_M3_COMPILE_DRIVER)"
    m3_emit_source="${ENTRY}"
  fi
  # Prefer capable gen-0 argv as emit helper (#22178 / #23970 / #27509) before the
  # committed .m3_compile_driver_aot_blob. That blob can still be a tiny Jul-era stub
  # that fails parseAndCompile while argv already compiles HelloWorld (#27426) —
  # selecting the stub first forced DEGRADED HelloWorld blob COPY (#21860).
  bootstrap_gen0_seed_prelinked_m3_sidecars || true
  m3_emit_helper_from_prelinked=0
  if [[ "${BOOTSTRAP_M3_FORCE_EMIT_HELPER_LINK:-0}" != "1" ]] \
    && [[ "${BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING:-1}" == "1" ]] \
    && helloworld_try_gen0_argv_as_emit_helper; then
    m3_emit_helper_from_prelinked=0
    m3_link_code=0
    m3_link_mode="gen-0 argv driver (#22178, #23970)"
    m3_link_out="bootstrap-selfhost-helloworld-probe: gen-0 argv emit helper (${EMIT_HELPER})"
    echo "bootstrap-selfhost-helloworld-probe: native emit helper from gen-0 argv driver (${EMIT_HELPER}, ${m3_link_mode})"
  elif [[ "${BOOTSTRAP_M3_FORCE_EMIT_HELPER_LINK:-0}" != "1" ]] \
    && [[ "${BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING:-1}" == "1" ]] \
    && bootstrap_gen0_sidecar_emit_fallback "${EMIT_HELPER}" "${m3_emit_source}"; then
    # Argv absent — use compile_driver sidecar only as last prelinked resort (#9704).
    m3_emit_helper_from_prelinked=1
    m3_link_code=0
    m3_link_out="bootstrap-selfhost-helloworld-probe: prelinked sidecar emit (${EMIT_HELPER}, #9704)"
    echo "bootstrap-selfhost-helloworld-probe: native emit helper from prelinked sidecar (${EMIT_HELPER}, ${m3_link_mode}, #9704)"
  else
    set +e
    echo "bootstrap-selfhost-helloworld-probe: linking native emit helper (${m3_link_mode})..."
    m3_link_out="$(
      "${m3_link_env[@]}" php "${ROOT}/bin/compile.php" -o "${EMIT_HELPER}" "${m3_emit_source}" 2>&1
    )"
    m3_link_code=$?
    set -e
  fi
  if [[ -x "${EMIT_HELPER}" ]]; then
    M3_EMIT_HELPER_LINKED=1
    echo "bootstrap-selfhost-helloworld-probe: native emit helper link OK (${EMIT_HELPER}, ${m3_link_mode})"
    if [[ "${BOOTSTRAP_M3_RUNTIME_COMPILE:-1}" == "1" ]]; then
      set +e
      # Prefer argv emit (BootstrapCompileSmokeM3Emit::emitMainEntry): DRIVER -o OUT SOURCE.
      # Env PHP_COMPILER_M3_SOURCE/OUT on the gen-0 argv driver segfaults at c:main_before_php;
      # argv avoids that and still prints helloworld_compile_smoke: compile OK (#22178).
      m3_run_env=(
        PHP_COMPILER_REPO_ROOT="${ROOT}"
        PHP_COMPILER_M3_COMPILE_MODE=compile
        PHP_COMPILER_M3_RUNTIME_COMPILE=1
        PHP_COMPILER_M3_EMIT_MINIMAL=1
        PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1
      )
      compile_out="$(
        env -u PHP_COMPILER_M3_SOURCE -u PHP_COMPILER_M3_OUT \
          "${m3_run_env[@]}" "${EMIT_HELPER}" -o "${AOT_OUT}" "${SOURCE}" 2>&1
      )"
      native_compile_code=$?
      # Ready-stub helpers ignore argv and only print "… ready"; fall back to env dispatch once.
      if [[ "${native_compile_code}" -eq 0 ]] \
        && grep -q 'compiler_helloworld_compile_driver ready' <<< "${compile_out}" \
        && ! grep -qE 'compile_smoke_m3_emit: compile OK|helloworld_compile_smoke: compile OK' <<< "${compile_out}"; then
        compile_out="$(
          env "${m3_run_env[@]}" \
            PHP_COMPILER_M3_SOURCE="${SOURCE}" \
            PHP_COMPILER_M3_OUT="${AOT_OUT}" \
            "${EMIT_HELPER}" 2>&1
        )"
        native_compile_code=$?
      fi
      set -e
      if [[ "${native_compile_code}" -eq 0 ]] && grep -qE 'compile_smoke_m3_emit: compile OK|helloworld_compile_smoke: compile OK' <<< "${compile_out}"; then
        M3_NATIVE_COMPILE=1
        M3_EMIT_PATH="native"
        M3_BLOCK_REASON=""
        echo "bootstrap-selfhost-helloworld-probe: native emit via selfhost emit helper OK"
      elif [[ "${m3_emit_helper_from_prelinked}" -eq 1 ]] \
        && bootstrap_gen0_sidecar_emit_fallback "${AOT_OUT}" "${SOURCE}"; then
        # The genuine native emit FAILED; this only COPIES a committed prelinked blob.
        # It is not a native emit and must not be reported as one (#21860).
        if grep -q 'native emit failed at phase=' <<< "${compile_out}"; then
          M3_SIDECAR_REASON="$(grep -m1 'native emit failed at phase=' <<< "${compile_out}" | sed 's/^compile_smoke_m3_emit: //')"
        elif [[ "${native_compile_code}" -eq 0 ]]; then
          M3_SIDECAR_REASON="emit helper exited 0 but printed no 'compile OK' marker"
        else
          M3_SIDECAR_REASON="emit helper $(m3_exit_label "${native_compile_code}")"
        fi
        M3_NATIVE_COMPILE=1
        M3_SIDECAR_FALLBACK=1
        M3_EMIT_PATH="native-prelinked-sidecar"
        M3_BLOCK_REASON=""
        echo "bootstrap-selfhost-helloworld-probe: native emit FAILED — ${M3_SIDECAR_REASON}" >&2
        printf '%s\n' "${compile_out}" >&2
        if [[ "${BOOTSTRAP_M3_REQUIRE_NATIVE_EMIT:-0}" == "1" ]]; then
          echo "bootstrap-selfhost-helloworld-probe: BOOTSTRAP_M3_REQUIRE_NATIVE_EMIT=1 — refusing prelinked sidecar COPY as a native emit (#21860)" >&2
          exit 1
        fi
        echo "bootstrap-selfhost-helloworld-probe: HelloWorld produced by prelinked sidecar COPY, not a native emit (${AOT_OUT}, #9704, #21860)" >&2
      else
        if grep -q 'native emit failed at phase=' <<< "${compile_out}"; then
          M3_BLOCK_REASON="$(grep -m1 'native emit failed at phase=' <<< "${compile_out}" | sed 's/^compile_smoke_m3_emit: //')"
        else
          M3_BLOCK_REASON="native emit runtime failed ($(m3_exit_label "${native_compile_code}"))"
        fi
        echo "bootstrap-selfhost-helloworld-probe: native emit blocked — ${M3_BLOCK_REASON}" >&2
        printf '%s\n' "${compile_out}" >&2
      fi
    else
      M3_BLOCK_REASON="runtime compile skipped (BOOTSTRAP_M3_RUNTIME_COMPILE=0)"
    fi
  else
    M3_BLOCK_REASON="emit helper link failed ($(m3_exit_label "${m3_link_code}"), mode=${m3_link_mode})"
    echo "bootstrap-selfhost-helloworld-probe: ${M3_BLOCK_REASON}" >&2
    printf '%s\n' "${m3_link_out}" >&2
  fi
fi

if helloworld_try_prelinked_smoke_probe; then
  :
elif ! bootstrap_compile_invoke "${PROBE}" "${ENTRY}" env PHP_COMPILER_SELFHOST_AOT=1 2>&1; then
  echo "bootstrap-selfhost-helloworld-probe: link bundle failed (see stderr above)" >&2
  exit 1
fi
test -x "${PROBE}"

bundle_out="$("${PROBE}")"
if ! grep -q 'compiler_helloworld_smoke bundle OK' <<< "${bundle_out}"; then
  echo "bootstrap-selfhost-helloworld-probe: unexpected bundle stdout (want compiler_helloworld_smoke bundle OK)" >&2
  printf '%s\n' "${bundle_out}" >&2
  exit 1
fi

if [[ "${M3_NATIVE_COMPILE}" -eq 0 ]]; then
  echo "bootstrap-selfhost-helloworld-probe: native emit unavailable (M3 partial) — ${M3_BLOCK_REASON}" >&2
  helloworld_m3_emit_next_lower
  if [[ "${BOOTSTRAP_M3_HELLOWORLD_STRICT:-0}" == "1" ]]; then
    echo "bootstrap-selfhost-helloworld-probe: BOOTSTRAP_M3_HELLOWORLD_STRICT=1 — require native emit; refusing Zend compile.php fallback" >&2
    echo "bootstrap-selfhost-helloworld-probe: emit_path=zend_fallback_would_be_used block_reason=${M3_BLOCK_REASON}" >&2
    exit 1
  fi
  M3_EMIT_PATH="zend"
  echo "bootstrap-selfhost-helloworld-probe: emit_path=zend (bin/compile.php) — HelloWorld AOT until native emit TU is stable" >&2
  rm -f "${AOT_OUT}"
  if ! php "${ROOT}/bin/compile.php" -o "${AOT_OUT}" "${SOURCE}" 2>&1; then
    echo "bootstrap-selfhost-helloworld-probe: Zend HelloWorld emit failed (emit_path=zend)" >&2
    exit 1
  fi
fi

if [[ ! -x "${AOT_OUT}" ]]; then
  echo "bootstrap-selfhost-helloworld-probe: missing executable ${AOT_OUT} (emit_path=${M3_EMIT_PATH})" >&2
  exit 1
fi

run_out="$("${AOT_OUT}" 2>&1)"
if ! grep -q 'Hello World' <<< "${run_out}"; then
  echo "bootstrap-selfhost-helloworld-probe: unexpected AOT stdout (want Hello World, emit_path=${M3_EMIT_PATH})" >&2
  printf '%s\n' "${run_out}" >&2
  exit 1
fi

if [[ "${M3_NATIVE_COMPILE}" -eq 1 && "${M3_SIDECAR_FALLBACK}" -eq 1 ]]; then
  echo "bootstrap-selfhost-helloworld-probe: DEGRADED emit_path=${M3_EMIT_PATH} ${EMIT_HELPER} -> ${AOT_OUT}"
  echo "bootstrap-selfhost-helloworld-probe:   native emit failed (${M3_SIDECAR_REASON}); output is a prelinked blob COPY (#21860)"
  echo "bootstrap-selfhost-helloworld-probe:   require a genuine native emit with BOOTSTRAP_M3_REQUIRE_NATIVE_EMIT=1"
elif [[ "${M3_NATIVE_COMPILE}" -eq 1 ]]; then
  echo "bootstrap-selfhost-helloworld-probe: OK emit_path=${M3_EMIT_PATH} ${EMIT_HELPER} -> ${AOT_OUT}"
else
  echo "bootstrap-selfhost-helloworld-probe: OK emit_path=zend partial ${PROBE} -> ${AOT_OUT} (native run)"
fi
printf 'helloworld-aot stdout: %s\n' "${run_out}"
