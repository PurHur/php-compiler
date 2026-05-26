#!/usr/bin/env bash
# Print MiniWebApp CI gate ladder status (issues #472, #503).
# Does not run full CI — probes phpc lint --all exit code unless --no-lint.
#
#   ./script/miniwebapp-gates.sh
#   ./script/miniwebapp-gates.sh --run-lint    # alias (lint probe is default)
#   MINIWEBAPP_LINT_GATE=0 ./script/miniwebapp-gates.sh   # skeleton opt-out
#
# See examples/003-MiniWebApp/README.md and make miniwebapp-gates.
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"
MINIWEBAPP=examples/003-MiniWebApp
REPO_URL="https://github.com/PurHur/php-compiler"

RUN_LINT=1
while [[ $# -gt 0 ]]; do
  case "$1" in
    --run-lint) RUN_LINT=1; shift ;;
    --no-lint) RUN_LINT=0; shift ;;
    -h|--help)
      cat <<'EOF'
Usage: script/miniwebapp-gates.sh [--no-lint]

Prints the progressive MiniWebApp CI gate ladder for examples/003-MiniWebApp.
Probes phpc lint --all by default; use --no-lint to report env/repo state only.

Environment (enable next gates):
  MINIWEBAPP_LINT_GATE=0      skeleton: web-smoke continues on lint failure (default gate on — #621)
  MINIWEBAPP_VM_CLI_GATE=1    run MiniWebApp*VmCli in ci-fast (default on; #597)
  MINIWEBAPP_SERVE_GATE=1     ServeTest MiniWebApp routes (default on in ci-local — #641)
  MINIWEBAPP_SERVE_GATE=0     skip ServeTest miniwebapp during fast iteration
  MINIWEBAPP_WEB_SMOKE_GATE=1     003 PATH_INFO curls in ci-local (default on — #664)
  MINIWEBAPP_WEB_SMOKE_GATE=0     skip shell PATH_INFO curls during iteration
  MINIWEBAPP_WEB_SMOKE_AOT_GATE=1 003 phpc serve --aot curls in ci-local (default on — #1523, #833)
  MINIWEBAPP_WEB_SMOKE_AOT_GATE=0 skip 003 AOT HTTP curls during iteration
  MINIWEBAPP_AOT_LINK_GATE=1      phpc build --project in ExamplesCompileTest (default on — #754)
  MINIWEBAPP_AOT_LINK_GATE=0      skip 003 native link gate during iteration
  MINIWEBAPP_AOT_EXECUTE_GATE=1   run 003 AOT binary CLI execute in ci-local (default — #747)
  MINIWEBAPP_AOT_EXECUTE_GATE=0   skip execute during iteration
  MINIWEBAPP_SERVE_AOT_GATE=1     MiniWebAppServeAotTest phpc serve --aot (default on — #1524, #478, #610)
  MINIWEBAPP_SERVE_AOT_GATE=0     skip serve-aot PHPUnit during iteration
  MINIWEBAPP_AOT_BISECT_GATE=1    run script/miniwebapp-aot-bisect.sh ladder (default off — #879)

See: examples/003-MiniWebApp/README.md, issue #472
EOF
      exit 0
      ;;
    *) echo "miniwebapp-gates: unknown argument: $1" >&2; exit 1 ;;
  esac
done

lint_exit=-1
if [[ ! -d "${MINIWEBAPP}/public" ]]; then
  echo "miniwebapp-gates: ${MINIWEBAPP}/public missing (#246)" >&2
  exit 1
fi

LINT_JSON="${TMPDIR:-/tmp}/miniwebapp-gates-lint.json"
if [[ "${RUN_LINT}" -eq 1 ]]; then
  lint_stderr="$(mktemp)"
  set +e
  "${ROOT}/phpc" lint --all "${MINIWEBAPP}" --json 2>"${lint_stderr}" >"${LINT_JSON}"
  lint_exit=$?
  set -e
  if [[ -s "${lint_stderr}" ]]; then
    cat "${lint_stderr}" >&2
  fi
  rm -f "${lint_stderr}"
fi

lint_gate="${MINIWEBAPP_LINT_GATE:-1}"
vm_cli_gate="${MINIWEBAPP_VM_CLI_GATE:-1}"
serve_gate="${MINIWEBAPP_SERVE_GATE:-1}"
serve_aot_gate="${MINIWEBAPP_SERVE_AOT_GATE:-1}"
serve_gate_explicit=0
if [[ -n "${MINIWEBAPP_SERVE_GATE+x}" ]]; then
  serve_gate_explicit=1
fi
web_smoke_gate="${MINIWEBAPP_WEB_SMOKE_GATE:-1}"
web_smoke_gate_explicit=0
if [[ -n "${MINIWEBAPP_WEB_SMOKE_GATE+x}" ]]; then
  web_smoke_gate_explicit=1
fi
web_smoke_aot_gate="${MINIWEBAPP_WEB_SMOKE_AOT_GATE:-1}"
web_smoke_aot_gate_explicit=0
if [[ -n "${MINIWEBAPP_WEB_SMOKE_AOT_GATE+x}" ]]; then
  web_smoke_aot_gate_explicit=1
fi

stage0=0
stage1=0
stage1b=0
stage2=0
stage3=0
stage3b=0
stage3c=0
stage3c_wired=0
stage4a=0
stage4serve=0
stage4c=0
stage4c_wired=0
stage4d=0
stage4=0
stage4b2=0
stage4b2bisect=0
aot_execute_probe_exit=-1
aot_execute_probe_skipped=0
aot_execute_probe_bytes=0
AOT_EXECUTE_PROBE_STDERR=""
aot_bisect_exit=-1
aot_bisect_skipped=1
aot_dry_run_exit=-1
aot_dry_run_skipped=0
aot_smoke_003_exit=-1
aot_smoke_003_skipped=0
AOT_SMOKE_003_STDERR=""
deploy_smoke_003_exit=-1
deploy_smoke_003_skipped=0
DEPLOY_SMOKE_003_STDERR=""
web_smoke_aot_003_exit=-1
web_smoke_aot_003_skipped=0
WEB_SMOKE_AOT_003_STDERR=""

resolve_llvm_dir() {
  if [[ -n "${PHP_COMPILER_LLVM_PATH:-}" ]]; then
    if [[ -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
      echo "${PHP_COMPILER_LLVM_PATH}"
      return 0
    fi
    return 1
  fi
  if [[ -f "${ROOT}/.llvm/libLLVM-9.so.1" ]]; then
    echo "${ROOT}/.llvm"
    return 0
  fi
  if [[ -f /opt/llvm9/libLLVM-9.so.1 ]]; then
    echo /opt/llvm9
    return 0
  fi
  return 1
}

# Stage 0: skeleton opt-out — MINIWEBAPP_LINT_GATE=0 skips lint failure in web-smoke.
if [[ "${lint_gate}" == "0" ]]; then
  stage0=1
fi

# Stage 1: phpc lint --all green (#539).
if [[ "${lint_exit}" -eq 0 ]]; then
  stage1=1
fi

# Stage 1b: ci-fast runs VM CLI route matrix (#597).
if grep -q 'MINIWEBAPP_VM_CLI_GATE' "${ROOT}/script/ci-fast.sh" 2>/dev/null \
  && [[ "${vm_cli_gate}" == "1" ]]; then
  stage1b=1
fi

# Stage 2: ServeTest gate default on in ci-local/ci-fast (#641, #470, #622).
if [[ "${serve_gate}" == "1" ]] \
  && grep -q 'MINIWEBAPP_SERVE_GATE:-1' "${ROOT}/script/ci-local.sh" 2>/dev/null; then
  stage2=1
fi

# Stage 3: examples-web-smoke curls 003 routes (#461).
if grep -q '003-MiniWebApp' "${ROOT}/script/examples-web-smoke.sh" 2>/dev/null; then
  stage3=1
fi

# Stage 3b: ci-local shell web-smoke gate default on (#633, #664).
if [[ "${web_smoke_gate}" == "1" ]] \
  && grep -q 'MINIWEBAPP_WEB_SMOKE_GATE:-1' "${ROOT}/script/ci-local.sh" 2>/dev/null; then
  stage3b=1
fi

# Stage 3c: ci-local 003 AOT web-smoke gate default on (#1523, #833) — probe below after LLVM_DIR.
stage3c_wired=0
if [[ "${web_smoke_aot_gate}" == "1" ]] \
  && grep -q 'MINIWEBAPP_WEB_SMOKE_AOT_GATE:-1' "${ROOT}/script/ci-defaults.env" 2>/dev/null \
  && grep -q 'ci_run_miniwebapp_web_smoke_aot' "${ROOT}/script/ci-common.sh" 2>/dev/null; then
  stage3c_wired=1
fi

# Stage 4a: phpc build --project --dry-run (#624, #675).
LLVM_DIR=""
AOT_DRY_RUN_STDERR=""
if LLVM_DIR="$(resolve_llvm_dir)"; then
  export PHP_COMPILER_LLVM_PATH="${LLVM_DIR}"
  aot_stderr="$(mktemp)"
  set +e
  "${ROOT}/phpc" build --project "${ROOT}/${MINIWEBAPP}" --dry-run 2>"${aot_stderr}" >/dev/null
  aot_dry_run_exit=$?
  set -e
  if [[ -s "${aot_stderr}" ]]; then
    AOT_DRY_RUN_STDERR="$(tail -n 8 "${aot_stderr}")"
  fi
  if [[ "${aot_dry_run_exit}" -eq 0 ]]; then
    stage4a=1
  fi
  rm -f "${aot_stderr}"
else
  aot_dry_run_skipped=1
fi

# Stage 4b2: AOT execute byte probe (#773) — MiniWebAppCgiEnv + .phpc/bin/app wc -c.
if [[ -n "${LLVM_DIR}" ]]; then
  aot_execute_binary="${ROOT}/${MINIWEBAPP}/.phpc/bin/app"
  aot_execute_build_stderr="$(mktemp)"
  set +e
  "${ROOT}/phpc" build --project "${ROOT}/${MINIWEBAPP}" 2>"${aot_execute_build_stderr}" >/dev/null
  aot_execute_build_exit=$?
  set -e
  if [[ -x "${aot_execute_binary}" ]]; then
    aot_execute_run_stderr="$(mktemp)"
    set +e
    eval "$( "${ROOT}/script/miniwebapp-cgi-env.php" --export shellQueryRouteHome )"
    eval "$( "${ROOT}/script/miniwebapp-cgi-env.php" --export aotFrontController )"
    aot_execute_out="$("${aot_execute_binary}" 2>"${aot_execute_run_stderr}")"
    aot_execute_probe_exit=$?
    set -e
    aot_execute_probe_bytes="$(printf '%s' "${aot_execute_out}" | wc -c | tr -d ' ')"
    if [[ -s "${aot_execute_run_stderr}" ]]; then
      AOT_EXECUTE_PROBE_STDERR="$(tail -n 8 "${aot_execute_run_stderr}")"
    fi
    rm -f "${aot_execute_run_stderr}"
    if [[ "${aot_execute_probe_exit}" -eq 0 && "${aot_execute_probe_bytes}" -gt 0 ]]; then
      stage4b2=1
    fi
  elif [[ -s "${aot_execute_build_stderr}" ]]; then
    AOT_EXECUTE_PROBE_STDERR="$(tail -n 8 "${aot_execute_build_stderr}")"
  fi
  rm -f "${aot_execute_build_stderr}"
elif [[ "${aot_dry_run_skipped}" -eq 1 ]]; then
  aot_execute_probe_skipped=1
fi

# Stage 4c wired: EXAMPLES_AOT_SMOKE_GATE default on in ci-local (#674, #764 closed).
if grep -q 'EXAMPLES_AOT_SMOKE_GATE:-1' "${ROOT}/script/ci-defaults.env" 2>/dev/null \
  && grep -q 'ci_run_examples_aot_smoke' "${ROOT}/script/ci-common.sh" 2>/dev/null; then
  stage4c_wired=1
fi

# Stage 4c: examples-aot-smoke 003 slice (#683, #881, #485; execute default on #747).
if [[ -n "${LLVM_DIR}" ]]; then
  aot_smoke_log="$(mktemp)"
  set +e
  EXAMPLES_AOT_SMOKE_ONLY=003 "${ROOT}/script/examples-aot-smoke.sh" >"${aot_smoke_log}" 2>&1
  aot_smoke_003_exit=$?
  set -e
  if [[ -s "${aot_smoke_log}" ]]; then
    AOT_SMOKE_003_STDERR="$(tail -n 8 "${aot_smoke_log}")"
  fi
  if [[ "${aot_smoke_003_exit}" -eq 0 ]] \
    && grep -q '003-MiniWebApp: skip' "${aot_smoke_log}" 2>/dev/null; then
    aot_smoke_003_skipped=1
  elif [[ "${aot_smoke_003_exit}" -eq 0 ]] \
    && grep -q '003-MiniWebApp: ok' "${aot_smoke_log}" 2>/dev/null; then
    stage4c=1
  fi
  rm -f "${aot_smoke_log}"
elif [[ "${aot_dry_run_skipped}" -eq 1 ]]; then
  aot_smoke_003_skipped=1
fi

# Stage 4d: deploy-smoke 003 execute (#745).
if [[ -n "${LLVM_DIR}" ]]; then
  deploy_smoke_log="$(mktemp)"
  set +e
  DEPLOY_SMOKE_003_EXECUTE=1 "${ROOT}/script/deploy-smoke.sh" --example 003 >"${deploy_smoke_log}" 2>&1
  deploy_smoke_003_exit=$?
  set -e
  if [[ -s "${deploy_smoke_log}" ]]; then
    DEPLOY_SMOKE_003_STDERR="$(tail -n 8 "${deploy_smoke_log}")"
  fi
  if [[ "${deploy_smoke_003_exit}" -eq 0 ]] \
    && grep -q '003-MiniWebApp: skip' "${deploy_smoke_log}" 2>/dev/null; then
    deploy_smoke_003_skipped=1
  elif [[ "${deploy_smoke_003_exit}" -eq 0 ]] \
    && grep -q '003-MiniWebApp: ok' "${deploy_smoke_log}" 2>/dev/null; then
    stage4d=1
  fi
  rm -f "${deploy_smoke_log}"
elif [[ "${aot_dry_run_skipped}" -eq 1 ]]; then
  deploy_smoke_003_skipped=1
fi

# Stage 3c probe: examples-web-smoke --miniwebapp-only --aot (#833, #1523).
if [[ -n "${LLVM_DIR}" && "${web_smoke_aot_gate}" == "1" ]]; then
  web_smoke_aot_log="$(mktemp)"
  set +e
  "${ROOT}/script/examples-web-smoke.sh" --miniwebapp-only --aot >"${web_smoke_aot_log}" 2>&1
  web_smoke_aot_003_exit=$?
  set -e
  if [[ -s "${web_smoke_aot_log}" ]]; then
    WEB_SMOKE_AOT_003_STDERR="$(tail -n 8 "${web_smoke_aot_log}")"
  fi
  if [[ "${web_smoke_aot_003_exit}" -eq 0 ]] \
    && grep -qE '003-MiniWebApp: skip|skipped \(' "${web_smoke_aot_log}" 2>/dev/null; then
    web_smoke_aot_003_skipped=1
  elif [[ "${web_smoke_aot_003_exit}" -eq 0 ]] \
    && grep -q 'examples-web-smoke: ok' "${web_smoke_aot_log}" 2>/dev/null; then
    stage3c=1
  fi
  rm -f "${web_smoke_aot_log}"
elif [[ "${aot_dry_run_skipped}" -eq 1 && "${web_smoke_aot_gate}" == "1" ]]; then
  web_smoke_aot_003_skipped=1
fi

# Stage 4b2: ordered #764 AOT PHPT ladder (opt-in MINIWEBAPP_AOT_BISECT_GATE=1, #879).
aot_bisect_gate="${MINIWEBAPP_AOT_BISECT_GATE:-0}"
if [[ "${aot_bisect_gate}" == "1" && -n "${LLVM_DIR}" ]]; then
  aot_bisect_skipped=0
  aot_bisect_stderr="$(mktemp)"
  set +e
  "${ROOT}/script/miniwebapp-aot-bisect.sh" 2>"${aot_bisect_stderr}" >/dev/null
  aot_bisect_exit=$?
  set -e
  if [[ "${aot_bisect_exit}" -eq 0 ]]; then
    stage4b2bisect=1
  fi
  rm -f "${aot_bisect_stderr}"
elif [[ "${aot_bisect_gate}" == "1" && -z "${LLVM_DIR}" ]]; then
  aot_bisect_skipped=1
fi

# Stage 4 serve-aot: MiniWebAppServeAotTest default on in ci-local (#1524, #1067).
serve_aot_test="${ROOT}/test/real/MiniWebAppServeAotTest.php"
if [[ -f "${serve_aot_test}" ]] \
  && grep -q 'MINIWEBAPP_SERVE_AOT_GATE:-1' "${ROOT}/script/ci-defaults.env" 2>/dev/null \
  && [[ "${serve_aot_gate}" == "1" ]]; then
  stage4serve=1
fi

# Stage 4b: ExamplesCompileTest link gate (#754).
compile_test="${ROOT}/test/unit/ExamplesCompileTest.php"
if [[ -f "${compile_test}" ]]; then
  if "${ROOT}/script/php-local.sh" -r '
$path = $argv[1];
$body = file_get_contents($path);
if (!preg_match("/function test003MiniWebAppBuildLinks\\(\\).*?\\n    \\}/s", $body, $m)) {
    exit(1);
}
// Gate=0 iteration skip is OK; fail only on unconditional link blockers (#568-style).
if (preg_match("/markTestSkipped\\([^)]*(?:#568|blocked)/", $m[0])) {
    exit(1);
}
exit(0);
' "${compile_test}" 2>/dev/null; then
    stage4=1
  fi
fi

mark() {
  if [[ "$1" -eq 1 ]]; then
    echo '[x]'
  else
    echo '[ ]'
  fi
}

echo "MiniWebApp CI gates (${MINIWEBAPP})"
echo "  Repo: ${REPO_URL}"
echo

if [[ "${RUN_LINT}" -eq 1 ]]; then
  if [[ "${lint_exit}" -eq 0 ]]; then
    echo "  Lint probe: green (phpc lint --all exit 0)"
  else
    echo "  Lint probe: exit ${lint_exit} (phpc lint --all)"
  fi
  if [[ "${lint_gate}" != "0" && "${lint_exit}" -ne 0 ]]; then
    echo "  lint gate on (default): web-smoke would fail (#621)"
  fi
  echo
fi

echo "$(mark "${stage0}") Stage 0 skeleton — MINIWEBAPP_LINT_GATE=0 skips lint failure in web-smoke (#621)"
if [[ "${stage0}" -eq 1 ]]; then
  echo "       unset or =1 to restore default lint gate in make web-smoke"
fi

echo "$(mark "${stage1}") Stage 1 lint green — make web-smoke fails on lint regression by default (#539, #621)"
echo "       ${REPO_URL}/issues/539"
echo "       ${REPO_URL}/issues/621"

echo "$(mark "${stage1b}") Stage 1b VM CLI — MINIWEBAPP_VM_CLI_GATE=1 in ci-fast (#597)"
echo "       ${REPO_URL}/issues/597  (unset or =0 to skip MiniWebApp*VmCli)"

echo "$(mark "${stage2}") Stage 2 ServeTest — MINIWEBAPP_SERVE_GATE=1 default in ci-local/ci-fast (#641, #470, #622)"
if [[ "${serve_gate_explicit}" -eq 1 ]]; then
  echo "       env: MINIWEBAPP_SERVE_GATE=${MINIWEBAPP_SERVE_GATE} (explicit)"
else
  echo "       env: default on (unset → 1 via ci-defaults.env / ci-local.sh)"
fi
echo "       ${REPO_URL}/issues/641"
echo "       ${REPO_URL}/issues/470"
echo "       ${REPO_URL}/issues/622"

echo "$(mark "${stage3}") Stage 3 web-smoke — make examples-web-smoke includes 003 (#461)"
echo "       ${REPO_URL}/issues/461"

echo "$(mark "${stage3b}") Stage 3b ci-local shell smoke — MINIWEBAPP_WEB_SMOKE_GATE=1 default in ci-local (#664, #633)"
if [[ "${web_smoke_gate_explicit}" -eq 1 ]]; then
  echo "       env: MINIWEBAPP_WEB_SMOKE_GATE=${MINIWEBAPP_WEB_SMOKE_GATE} (explicit)"
else
  echo "       env: default on (unset → 1 via ci-defaults.env / ci-local.sh)"
fi
echo "       ${REPO_URL}/issues/664"
echo "       ${REPO_URL}/issues/633"

echo "$(mark "${stage3c}") Stage 3c ci-local AOT web-smoke — MINIWEBAPP_WEB_SMOKE_AOT_GATE=1 default in ci-local (#1523, #833)"
if [[ "${web_smoke_aot_gate_explicit}" -eq 1 ]]; then
  echo "       env: MINIWEBAPP_WEB_SMOKE_AOT_GATE=${MINIWEBAPP_WEB_SMOKE_AOT_GATE} (explicit)"
else
  echo "       env: default on (unset → 1 via ci-defaults.env / ci-local.sh)"
fi
echo "       ${REPO_URL}/issues/1523"
echo "       ${REPO_URL}/issues/833"
echo "       MINIWEBAPP_WEB_SMOKE_AOT_GATE=0 skips 003 AOT HTTP curls during iteration"
if [[ "${stage3c_wired}" -eq 0 ]]; then
  echo "       gate not wired in ci-defaults.env / ci-common.sh"
elif [[ "${aot_dry_run_skipped}" -eq 1 && -z "${LLVM_DIR}" ]]; then
  echo "       LLVM 9 not available (script/install-llvm9.sh or .llvm/)"
elif [[ "${web_smoke_aot_gate}" != "1" ]]; then
  echo "       003 AOT web-smoke probe: skipped (MINIWEBAPP_WEB_SMOKE_AOT_GATE=0)"
elif [[ "${web_smoke_aot_003_skipped}" -eq 1 ]]; then
  echo "       003 AOT web-smoke probe: skipped (serve/loopback)"
  if [[ -n "${WEB_SMOKE_AOT_003_STDERR}" ]]; then
    echo "${WEB_SMOKE_AOT_003_STDERR}" | sed 's/^/         /'
  fi
elif [[ "${web_smoke_aot_003_exit}" -eq 0 && "${stage3c}" -eq 1 ]]; then
  echo "       003 AOT web-smoke probe: green"
elif [[ "${web_smoke_aot_003_exit}" -ge 0 ]]; then
  echo "       003 AOT web-smoke probe: exit ${web_smoke_aot_003_exit} (see #833/#676)"
  if [[ -n "${WEB_SMOKE_AOT_003_STDERR}" ]]; then
    echo "${WEB_SMOKE_AOT_003_STDERR}" | sed 's/^/         /'
  fi
fi

echo "$(mark "${stage4a}") Stage 4a AOT dry-run — phpc build --project --dry-run (#624, #675)"
echo "       ${REPO_URL}/issues/624"
if [[ "${aot_dry_run_skipped}" -eq 1 ]]; then
  echo "       LLVM 9 not available (script/install-llvm9.sh or .llvm/)"
elif [[ "${aot_dry_run_exit}" -eq 0 ]]; then
  echo "       AOT dry-run probe: green"
elif [[ "${aot_dry_run_exit}" -ge 0 ]]; then
  echo "       AOT dry-run probe: exit ${aot_dry_run_exit} (see #58/#764)"
  if [[ -n "${AOT_DRY_RUN_STDERR}" ]]; then
    echo "${AOT_DRY_RUN_STDERR}" | sed 's/^/         /'
  fi
fi

echo "$(mark "${stage4serve}") Stage 4 serve-aot — MiniWebAppServeAotTest (MINIWEBAPP_SERVE_AOT_GATE=1 default — #1524, #1067)"
echo "       ${REPO_URL}/issues/1524"
echo "       ${REPO_URL}/issues/1067"
echo "       MINIWEBAPP_SERVE_AOT_GATE=0 skips serve-aot PHPUnit during iteration"

echo "$(mark "${stage4d}") Stage 4d deploy-smoke — phpc deploy + PHPC_DEPLOY_ROOT 003 execute (#745, #718, #1530)"
echo "       ${REPO_URL}/issues/745"
echo "       ${REPO_URL}/issues/718"
echo "       DEPLOY_SMOKE_003_EXECUTE=1 default in ci-defaults.env (#1530)"
echo "       DEPLOY_SMOKE_003_EXECUTE=0 ./script/deploy-smoke.sh --example 003   # opt-out"
if [[ "${aot_dry_run_skipped}" -eq 1 && -z "${LLVM_DIR}" ]]; then
  echo "       LLVM 9 not available (script/install-llvm9.sh or .llvm/)"
elif [[ "${deploy_smoke_003_skipped}" -eq 1 ]]; then
  echo "       003 deploy execute probe: skipped (DEPLOY_SMOKE_003_EXECUTE=0)"
  if [[ -n "${DEPLOY_SMOKE_003_STDERR}" ]]; then
    echo "${DEPLOY_SMOKE_003_STDERR}" | sed 's/^/         /'
  fi
elif [[ "${deploy_smoke_003_exit}" -eq 0 && "${stage4d}" -eq 1 ]]; then
  echo "       003 deploy execute probe: green"
elif [[ "${deploy_smoke_003_exit}" -ge 0 ]]; then
  echo "       003 deploy execute probe: exit ${deploy_smoke_003_exit} (see #745/#676)"
  if [[ -n "${DEPLOY_SMOKE_003_STDERR}" ]]; then
    echo "${DEPLOY_SMOKE_003_STDERR}" | sed 's/^/         /'
  fi
fi

echo "$(mark "${stage4c}") Stage 4c AOT smoke — examples-aot-smoke 003 slice (#683, #881, #485)"
echo "       ${REPO_URL}/issues/683"
echo "       ${REPO_URL}/issues/881"
echo "       ${REPO_URL}/issues/485"
echo "       EXAMPLES_AOT_SMOKE_GATE=1 default in ci-defaults.env (#674); EXAMPLES_AOT_SMOKE_ONLY=003 for ladder probe"
echo "       EXAMPLES_AOT_SMOKE_GATE=0 ./script/ci-local.sh   # skip examples-aot-smoke during iteration"
if [[ "${stage4c_wired}" -eq 0 ]]; then
  echo "       gate not wired in ci-defaults.env / ci-common.sh"
elif [[ "${aot_dry_run_skipped}" -eq 1 && -z "${LLVM_DIR}" ]]; then
  echo "       LLVM 9 not available (script/install-llvm9.sh or .llvm/)"
elif [[ "${aot_smoke_003_skipped}" -eq 1 ]]; then
  echo "       003 slice probe: skipped (MINIWEBAPP_AOT_EXECUTE_GATE=0 or examples-aot-smoke skip)"
  if [[ -n "${AOT_SMOKE_003_STDERR}" ]]; then
    echo "${AOT_SMOKE_003_STDERR}" | sed 's/^/         /'
  fi
elif [[ "${aot_smoke_003_exit}" -eq 0 && "${stage4c}" -eq 1 ]]; then
  echo "       003 slice probe: green"
elif [[ "${aot_smoke_003_exit}" -ge 0 ]]; then
  echo "       003 slice probe: exit ${aot_smoke_003_exit} (see #683/#881/#485)"
  if [[ -n "${AOT_SMOKE_003_STDERR}" ]]; then
    echo "${AOT_SMOKE_003_STDERR}" | sed 's/^/         /'
  fi
fi

echo "$(mark "${stage4}") Stage 4b AOT link — test003MiniWebAppBuildLinks (MINIWEBAPP_AOT_LINK_GATE=1 default — #754, #454)"
echo "       ${REPO_URL}/issues/754"
echo "       ${REPO_URL}/issues/454"
echo "       MINIWEBAPP_AOT_LINK_GATE=0 skips link test during iteration"

echo "$(mark "${stage4b2}") Stage 4b2 AOT execute — native binary + MiniWebAppCgiEnv byte probe (#773)"
echo "       ${REPO_URL}/issues/773"
if [[ "${aot_execute_probe_skipped}" -eq 1 ]]; then
  echo "       LLVM 9 not available (script/install-llvm9.sh or .llvm/)"
elif [[ "${stage4b2}" -eq 1 ]]; then
  echo "       AOT execute probe: green (${aot_execute_probe_bytes} stdout bytes)"
elif [[ "${aot_execute_probe_exit}" -eq 0 && "${aot_execute_probe_bytes}" -eq 0 ]]; then
  echo "       AOT execute probe: empty stdout"
  echo "       phpc doctor --aot-project-probe   # fast build+execute preflight (#746)"
  if [[ -n "${AOT_EXECUTE_PROBE_STDERR}" ]]; then
    echo "${AOT_EXECUTE_PROBE_STDERR}" | sed 's/^/         /'
  fi
elif [[ "${aot_execute_probe_exit}" -ge 0 ]]; then
  echo "       AOT execute probe: exit ${aot_execute_probe_exit}"
  echo "       phpc doctor --aot-project-probe   # fast build+execute preflight (#746)"
  if [[ -n "${AOT_EXECUTE_PROBE_STDERR}" ]]; then
    echo "${AOT_EXECUTE_PROBE_STDERR}" | sed 's/^/         /'
  fi
fi

echo "$(mark "${stage4b2bisect}") Stage 4b2 bisect — MINIWEBAPP_AOT_BISECT_GATE=1 runs miniwebapp-aot-bisect.sh (#879, #764)"
echo "       ${REPO_URL}/issues/879"
echo "       ${REPO_URL}/issues/764"
if [[ "${aot_bisect_gate}" != "1" ]]; then
  echo "       env: default off (MINIWEBAPP_AOT_BISECT_GATE=1 to probe ordered PHPT ladder)"
elif [[ "${aot_bisect_skipped}" -eq 1 ]]; then
  echo "       LLVM 9 not available (script/install-llvm9.sh or .llvm/)"
elif [[ "${aot_bisect_exit}" -eq 0 ]]; then
  echo "       AOT bisect probe: green"
elif [[ "${aot_bisect_exit}" -ge 0 ]]; then
  echo "       AOT bisect probe: exit ${aot_bisect_exit}"
fi

echo
echo "Commands:"
echo "  make web-smoke              lint + VM smoke (#455)"
echo "  make examples-web-smoke     phpc serve + curl (#298)"
echo "  make web-smoke              lint gate on by default (#621)"
echo "  MINIWEBAPP_LINT_GATE=0 make web-smoke"
echo "  MINIWEBAPP_VM_CLI_GATE=1 ./script/ci-fast.sh"
echo "  ./script/ci-local.sh --filter ServeTest   # MINIWEBAPP_SERVE_GATE=1 by default (#641)"
echo "  MINIWEBAPP_SERVE_GATE=0 ./script/ci-local.sh   # skip miniwebapp ServeTest"
echo "  ./script/ci-local.sh   # MINIWEBAPP_WEB_SMOKE_GATE=1 by default (#664)"
echo "  MINIWEBAPP_WEB_SMOKE_GATE=0 ./script/ci-local.sh   # skip 003 shell curls"
echo "  MINIWEBAPP_WEB_SMOKE_AOT_GATE=1 ./script/examples-web-smoke.sh --miniwebapp-only --aot   # stage 3c (#1523)"
echo "  MINIWEBAPP_WEB_SMOKE_AOT_GATE=0 ./script/ci-local.sh   # skip 003 AOT HTTP curls"
echo "  ./phpc build --project examples/003-MiniWebApp --dry-run   # stage 4a (#624)"
echo "  eval \"\$(./script/miniwebapp-cgi-env.php --export shellQueryRouteHome)\"   # stage 4b2 (#773)"
echo "  eval \"\$(./script/miniwebapp-cgi-env.php --export aotFrontController)\""
echo "  examples/003-MiniWebApp/.phpc/bin/app | wc -c   # stage 4b2 byte probe (#773)"
echo "  EXAMPLES_AOT_SMOKE_ONLY=003 ./script/examples-aot-smoke.sh   # stage 4c (#683)"
echo "  make deploy-smoke             # stage 4d deploy 001/002; 003 execute via DEPLOY_SMOKE_003_EXECUTE=1 (#745)"
echo "  DEPLOY_SMOKE_ONLY=003 DEPLOY_SMOKE_003_EXECUTE=1 ./script/deploy-smoke.sh   # stage 4d 003 slice (#745)"
echo "  ./script/ci-local.sh --filter MiniWebAppServeAot   # stage 4 serve-aot (#1524)"
echo "  MINIWEBAPP_SERVE_AOT_GATE=0 ./script/ci-local.sh --filter MiniWebAppServeAot   # skip serve-aot"
echo "  ./script/ci-local.sh --filter test003MiniWebAppBuildLinks   # stage 4b link (#754)"
echo "  MINIWEBAPP_AOT_LINK_GATE=0 ./script/ci-local.sh --filter ExamplesCompileTest   # skip 003 link"
echo "  ./script/ci-local.sh --filter test003MiniWebAppProjectAotLint   # stage 4a dry-run (#624)"
echo "  MINIWEBAPP_AOT_BISECT_GATE=1 ./script/miniwebapp-aot-bisect.sh   # stage 4b2 (#879)"
echo "  ./script/miniwebapp-aot-bisect.sh --list"
echo
echo "Tracking: ${REPO_URL}/issues/472 (gate ladder spec)"
echo
echo "Doc sync gates (ci-fast inventory — phpc doctor --gates does not run these):"
root_readme_gate="${ROOT_README_SYNC_GATE:-1}"
examples_readme_gate="${EXAMPLES_README_SYNC_GATE:-1}"
wave3_gate="${WAVE3_ROADMAP_SYNC_GATE:-1}"
nested_return_gate="${NESTED_RETURN_COMPLIANCE_GATE:-1}"
attributes_gate="${ATTRIBUTES_COMPLIANCE_GATE:-1}"
rehash_gate="${REHASH_COMPLIANCE_GATE:-1}"
coalesce_gate="${COALESCE_COMPLIANCE_GATE:-1}"
jit_var_fn_gate="${JIT_VARIABLE_FUNCTION_COMPLIANCE_GATE:-1}"
jit_server_gate="${JIT_SERVER_SUPERGLOBAL_GATE:-0}"
echo "  ROOT_README_SYNC_GATE=${root_readme_gate}  script/check-root-readme-sync.php (#1832, #1525)"
echo "  EXAMPLES_README_SYNC_GATE=${examples_readme_gate}  script/check-examples-readme-sync.php (#1822)"
echo "  WAVE3_ROADMAP_SYNC_GATE=${wave3_gate}  script/check-wave3-roadmap-sync.php (#1802)"
m5_doc_gate="${BOOTSTRAP_M5_DOC_SYNC_GATE:-1}"
echo "  BOOTSTRAP_M5_DOC_SYNC_GATE=${m5_doc_gate}  script/check-bootstrap-m5-doc-sync.php (#1984)"
spine_coverage_gate="${SELFHOST_SPINE_COVERAGE_SYNC_GATE:-1}"
echo "  SELFHOST_SPINE_COVERAGE_SYNC_GATE=${spine_coverage_gate}  script/check-selfhost-spine-coverage-sync.php (#1955)"
echo "  NESTED_RETURN_COMPLIANCE_GATE=${nested_return_gate}  ci-fast NestedReturn* (#1888, #1885)"
echo "  ATTRIBUTES_COMPLIANCE_GATE=${attributes_gate}  ci-fast Attribute* (#1904, #1354)"
echo "  REHASH_COMPLIANCE_GATE=${rehash_gate}  ci-fast array_rehash_string_keys (#1956, #66)"
echo "  COALESCE_COMPLIANCE_GATE=${coalesce_gate}  ci-fast Coalesce* (#1960, #99)"
echo "  JIT_VARIABLE_FUNCTION_COMPLIANCE_GATE=${jit_var_fn_gate}  ci-fast/ci-local VariableFunction* JIT (#2060, #2055)"
echo "  JIT_SERVER_SUPERGLOBAL_GATE=${jit_server_gate}  ci-local JitServerSuperglobal (#2257, #2275)"
miniwebapp_parity_gate="${INIT_MINIWEBAPP_PARITY_GATE:-1}"
echo "  INIT_MINIWEBAPP_PARITY_GATE=${miniwebapp_parity_gate}  script/check-init-miniwebapp-parity.sh (#2057, #695)"
lint_zero_gate="${MINIWEBAPP_LINT_ZERO_GATE:-1}"
echo "  MINIWEBAPP_LINT_ZERO_GATE=${lint_zero_gate}  script/check-miniwebapp-lint-zero.php (#2078, #539)"
echo "  Opt out: ROOT_README_SYNC_GATE=0 ./script/ci-fast.sh"
echo "  Opt out: INIT_MINIWEBAPP_PARITY_GATE=0 ./script/ci-fast.sh"
echo "  Opt out: MINIWEBAPP_LINT_ZERO_GATE=0 ./script/ci-fast.sh"
echo "  Opt out: SELFHOST_SPINE_COVERAGE_SYNC_GATE=0 ./script/ci-fast.sh"
echo "  Opt out: NESTED_RETURN_COMPLIANCE_GATE=0 ./script/ci-fast.sh"
echo "  Opt out: ATTRIBUTES_COMPLIANCE_GATE=0 ./script/ci-fast.sh"
echo "  Opt out: REHASH_COMPLIANCE_GATE=0 ./script/ci-fast.sh"
echo "  Opt out: COALESCE_COMPLIANCE_GATE=0 ./script/ci-fast.sh"
echo "  Opt out: JIT_VARIABLE_FUNCTION_COMPLIANCE_GATE=0 ./script/ci-fast.sh"
echo "  Opt in:  JIT_SERVER_SUPERGLOBAL_GATE=1 ./script/ci-local.sh --filter JitServerSuperglobal"

# Current focus
if [[ "${lint_exit}" -ne 0 && "${lint_gate}" != "0" ]]; then
  echo "Next: fix lint regressions (web-smoke lint gate is on by default — #621)"
elif [[ "${lint_exit}" -ne 0 && "${lint_gate}" == "0" ]]; then
  echo "Next: close lint blockers (#539); default gate will fail web-smoke when lint is fixed"
elif [[ "${lint_exit}" -eq 0 && "${vm_cli_gate}" != "1" ]]; then
  echo "Next: export MINIWEBAPP_VM_CLI_GATE=1 (lint green; enable VM CLI in ci-fast — #597)"
elif [[ "${serve_gate}" != "1" ]]; then
  echo "Next: unset MINIWEBAPP_SERVE_GATE=0 or export =1 for stage 2 ServeTest (#641)"
elif [[ "${web_smoke_gate}" != "1" ]]; then
  echo "Next: unset MINIWEBAPP_WEB_SMOKE_GATE=0 or export =1 for ci-local shell PATH_INFO curls (#664)"
elif [[ "${stage3}" -eq 0 ]]; then
  echo "Next: extend script/examples-web-smoke.sh for 003 (#461)"
elif [[ "${stage4a}" -eq 0 && "${aot_dry_run_skipped}" -eq 0 && "${aot_dry_run_exit}" -ne 0 ]]; then
  echo "Next: fix MiniWebApp AOT dry-run blockers (#58) — stage 4a (#624)"
elif [[ "${stage4a}" -eq 0 && "${aot_dry_run_skipped}" -eq 1 ]]; then
  echo "Next: install LLVM 9 for stage 4a AOT dry-run (script/install-llvm9.sh)"
elif [[ "${stage4b2}" -eq 0 && "${aot_execute_probe_skipped}" -eq 0 ]]; then
  echo "Next: green stage 4b2 AOT execute byte probe (#773); try phpc doctor --aot-project-probe (#746)"
elif [[ "${stage4c}" -eq 0 && "${aot_smoke_003_skipped}" -eq 1 ]]; then
  echo "Next: stage 4c examples-aot-smoke 003 with MINIWEBAPP_AOT_EXECUTE_GATE=1 (#683, #485)"
elif [[ "${stage4c}" -eq 0 && "${aot_smoke_003_exit}" -ne 0 ]]; then
  echo "Next: fix examples-aot-smoke 003 slice failures (#485, #683)"
elif [[ "${stage4}" -eq 0 ]]; then
  echo "Next: green test003MiniWebAppBuildLinks when LLVM ready (#754, #454)"
else
  echo "All documented gates are enabled in this tree."
fi

# Lint JSON blockers when gate enforced or lint still failing
if [[ "${RUN_LINT}" -eq 1 && -s "${LINT_JSON}" && "${lint_exit}" -ne 0 ]]; then
  if [[ "${lint_gate}" != "0" || "${lint_exit}" -ne 0 ]]; then
    echo
    echo "Lint blockers (phpc lint --all --json):"
    "${ROOT}/script/php-local.sh" -r '
$path = $argv[1];
$enforce = $argv[2] === "1";
$raw = file_get_contents($path);
$data = json_decode($raw, true);
if (!is_array($data) || !isset($data["issues"]) || !is_array($data["issues"])) {
    fwrite(STDERR, "miniwebapp-gates: could not parse lint JSON at {$path}\n");
    exit(0);
}
$issues = $data["issues"];
$count = count($issues);
echo "  {$count} issue(s)\n";
$urls = [];
foreach ($issues as $issue) {
    if (!empty($issue["issue_url"])) {
        $urls[$issue["issue_url"]] = true;
    } elseif (!empty($issue["issue"])) {
        $urls["https://github.com/PurHur/php-compiler/issues/".$issue["issue"]] = true;
    }
}
if ($urls !== []) {
    ksort($urls);
    echo "  Tracking URLs:\n";
    foreach (array_keys($urls) as $url) {
        echo "    {$url}\n";
    }
}
$shown = 0;
foreach ($issues as $issue) {
    if ($shown >= 12) {
        $rest = $count - $shown;
        if ($rest > 0) {
            echo "  … and {$rest} more\n";
        }
        break;
    }
    $file = $issue["file"] ?? "?";
    $line = $issue["line"] ?? 0;
    $kind = $issue["kind"] ?? "?";
    $url = $issue["issue_url"] ?? "";
    if ($url === "" && !empty($issue["issue"])) {
        $url = "https://github.com/PurHur/php-compiler/issues/".$issue["issue"];
    }
    $suffix = $url !== "" ? " -> {$url}" : "";
    echo "  {$file}:{$line}: {$kind}{$suffix}\n";
    ++$shown;
}
if ($enforce && $count > 0) {
    exit(0);
}
' "${LINT_JSON}" "${lint_gate}"
  fi
fi

exit 0
