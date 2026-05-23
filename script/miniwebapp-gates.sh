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
  MINIWEBAPP_AOT_LINK_GATE=1      phpc build --project in ExamplesCompileTest (default on — #754)
  MINIWEBAPP_AOT_LINK_GATE=0      skip 003 native link gate during iteration
  MINIWEBAPP_AOT_EXECUTE_GATE=1   run 003 AOT binary CLI execute in ci-local (#747, #764)
  MINIWEBAPP_AOT_EXECUTE_GATE=0   skip execute (default until #764 green — #791)
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
serve_gate_explicit=0
if [[ -n "${MINIWEBAPP_SERVE_GATE+x}" ]]; then
  serve_gate_explicit=1
fi
web_smoke_gate="${MINIWEBAPP_WEB_SMOKE_GATE:-1}"
web_smoke_gate_explicit=0
if [[ -n "${MINIWEBAPP_WEB_SMOKE_GATE+x}" ]]; then
  web_smoke_gate_explicit=1
fi

stage0=0
stage1=0
stage1b=0
stage2=0
stage3=0
stage3b=0
stage4a=0
stage4c=0
stage4=0
stage4b2=0
aot_bisect_exit=-1
aot_bisect_skipped=1
aot_dry_run_exit=-1
aot_dry_run_skipped=0
aot_smoke_003_exit=-1
aot_smoke_003_skipped=0
AOT_SMOKE_003_STDERR=""

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

# Stage 4c: examples-aot-smoke 003 slice (#683, #485; blocked #568).
if [[ -n "${LLVM_DIR}" ]]; then
  aot_smoke_stderr="$(mktemp)"
  set +e
  EXAMPLES_AOT_SMOKE_ONLY=003 "${ROOT}/script/examples-aot-smoke.sh" 2>"${aot_smoke_stderr}" >/dev/null
  aot_smoke_003_exit=$?
  set -e
  if [[ -s "${aot_smoke_stderr}" ]]; then
    AOT_SMOKE_003_STDERR="$(tail -n 8 "${aot_smoke_stderr}")"
  fi
  if [[ "${aot_smoke_003_exit}" -eq 0 ]] \
    && grep -q '003-MiniWebApp: skip' "${aot_smoke_stderr}" 2>/dev/null; then
    aot_smoke_003_skipped=1
  elif [[ "${aot_smoke_003_exit}" -eq 0 ]] \
    && grep -q '003-MiniWebApp: ok' "${aot_smoke_stderr}" 2>/dev/null; then
    stage4c=1
  fi
  rm -f "${aot_smoke_stderr}"
elif [[ "${aot_dry_run_skipped}" -eq 1 ]]; then
  aot_smoke_003_skipped=1
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
    stage4b2=1
  fi
  rm -f "${aot_bisect_stderr}"
elif [[ "${aot_bisect_gate}" == "1" && -z "${LLVM_DIR}" ]]; then
  aot_bisect_skipped=1
fi

# Stage 4b: ExamplesCompileTest @group miniwebapp unskipped (#454).
compile_test="${ROOT}/test/unit/ExamplesCompileTest.php"
if [[ -f "${compile_test}" ]]; then
  if "${ROOT}/script/php-local.sh" -r '
$path = $argv[1];
$body = file_get_contents($path);
if (!preg_match("/function test003MiniWebAppEventuallyRuns\\(\\).*?\\n    \\}/s", $body, $m)) {
    exit(1);
}
exit(str_contains($m[0], "markTestSkipped") ? 1 : 0);
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

echo "$(mark "${stage4a}") Stage 4a AOT dry-run — phpc build --project --dry-run (#624, #675)"
echo "       ${REPO_URL}/issues/624"
if [[ "${aot_dry_run_skipped}" -eq 1 ]]; then
  echo "       LLVM 9 not available (script/install-llvm9.sh or .llvm/)"
elif [[ "${aot_dry_run_exit}" -eq 0 ]]; then
  echo "       AOT dry-run probe: green"
elif [[ "${aot_dry_run_exit}" -ge 0 ]]; then
  echo "       AOT dry-run probe: exit ${aot_dry_run_exit} (see #58/#568)"
  if [[ -n "${AOT_DRY_RUN_STDERR}" ]]; then
    echo "${AOT_DRY_RUN_STDERR}" | sed 's/^/         /'
  fi
fi

echo "$(mark "${stage4c}") Stage 4c AOT smoke — examples-aot-smoke 003 slice (#683, #485)"
echo "       ${REPO_URL}/issues/683"
echo "       ${REPO_URL}/issues/485"
echo "$(mark 0) Stage 4d deploy-smoke — make deploy-smoke / PHPC_DEPLOY_ROOT (#718; 003 blocked #568, PHPUnit #612)"
echo "       ${REPO_URL}/issues/718"
echo "       ${REPO_URL}/issues/612"
echo "       make deploy-smoke   # 001/002 when LLVM ready; not wired into ci-local until 003 green"
if [[ "${aot_dry_run_skipped}" -eq 1 && -z "${LLVM_DIR}" ]]; then
  echo "       LLVM 9 not available (script/install-llvm9.sh or .llvm/)"
elif [[ "${aot_smoke_003_skipped}" -eq 1 ]]; then
  echo "       003 slice probe: skipped (#568)"
  if [[ -n "${AOT_SMOKE_003_STDERR}" ]]; then
    echo "${AOT_SMOKE_003_STDERR}" | sed 's/^/         /'
  fi
elif [[ "${aot_smoke_003_exit}" -eq 0 && "${stage4c}" -eq 1 ]]; then
  echo "       003 slice probe: green"
elif [[ "${aot_smoke_003_exit}" -ge 0 ]]; then
  echo "       003 slice probe: exit ${aot_smoke_003_exit} (see #568/#485)"
  if [[ -n "${AOT_SMOKE_003_STDERR}" ]]; then
    echo "${AOT_SMOKE_003_STDERR}" | sed 's/^/         /'
  fi
fi

echo "$(mark "${stage4}") Stage 4b AOT link — ExamplesCompileTest @group miniwebapp unskipped (#454, #568)"
echo "       ${REPO_URL}/issues/454"
echo "       ${REPO_URL}/issues/568"

echo "$(mark "${stage4b2}") Stage 4b2 AOT bisect — MINIWEBAPP_AOT_BISECT_GATE=1 runs miniwebapp-aot-bisect.sh (#879, #764)"
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
echo "  ./phpc build --project examples/003-MiniWebApp --dry-run   # stage 4a (#624)"
echo "  EXAMPLES_AOT_SMOKE_ONLY=003 ./script/examples-aot-smoke.sh   # stage 4c (#683)"
echo "  make deploy-smoke             # stage 4d shell gate 001/002 (#718)"
echo "  ./script/ci-local.sh --filter test003MiniWebAppProjectAotLint"
echo "  MINIWEBAPP_AOT_BISECT_GATE=1 ./script/miniwebapp-aot-bisect.sh   # stage 4b2 (#879)"
echo "  ./script/miniwebapp-aot-bisect.sh --list"
echo
echo "Tracking: ${REPO_URL}/issues/472 (gate ladder spec)"

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
elif [[ "${stage4c}" -eq 0 && "${aot_smoke_003_skipped}" -eq 1 ]]; then
  echo "Next: native user-class AOT (#568) to green stage 4c examples-aot-smoke 003 (#683, #485)"
elif [[ "${stage4c}" -eq 0 && "${aot_smoke_003_exit}" -ne 0 ]]; then
  echo "Next: fix examples-aot-smoke 003 slice failures (#485, #683)"
elif [[ "${stage4}" -eq 0 ]]; then
  echo "Next: unskip test003MiniWebAppEventuallyRuns in ExamplesCompileTest (#454, #568)"
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
