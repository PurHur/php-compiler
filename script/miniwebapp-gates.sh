#!/usr/bin/env bash
# Print MiniWebApp CI gate ladder status (issues #472, #503).
# Does not run full CI — probes phpc lint --all exit code unless --no-lint.
#
#   ./script/miniwebapp-gates.sh
#   ./script/miniwebapp-gates.sh --run-lint    # alias (lint probe is default)
#   MINIWEBAPP_LINT_GATE=1 ./script/miniwebapp-gates.sh
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
  MINIWEBAPP_LINT_GATE=1   fail make web-smoke when lint regresses (#539, #455)
  MINIWEBAPP_SERVE_GATE=1  enforce ServeTest MiniWebApp routes (#470)

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

lint_gate="${MINIWEBAPP_LINT_GATE:-}"
serve_gate="${MINIWEBAPP_SERVE_GATE:-}"

stage0=0
stage1=0
stage2=0
stage3=0
stage4=0

# Stage 0: skeleton — web-smoke does not fail on lint exit 1 (default).
if [[ "${lint_gate}" != "1" ]]; then
  stage0=1
fi

# Stage 1: phpc lint --all green (#539).
if [[ "${lint_exit}" -eq 0 ]]; then
  stage1=1
fi

# Stage 2: ServeTest gate env enabled (#470); routes land in ServeTest when green.
if [[ "${serve_gate}" == "1" ]]; then
  stage2=1
fi

# Stage 3: examples-web-smoke curls 003 routes (#461).
if grep -q '003-MiniWebApp' "${ROOT}/script/examples-web-smoke.sh" 2>/dev/null; then
  stage3=1
fi

# Stage 4: ExamplesCompileTest @group miniwebapp unskipped (#454).
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
  if [[ "${lint_gate}" == "1" && "${lint_exit}" -ne 0 ]]; then
    echo "  MINIWEBAPP_LINT_GATE=1: web-smoke would fail (#539)"
  fi
  echo
fi

echo "$(mark "${stage0}") Stage 0 skeleton — web-smoke continues on lint exit 1 (default; #455)"
if [[ "${stage0}" -eq 0 ]]; then
  echo "       unset MINIWEBAPP_LINT_GATE to return to skeleton mode"
fi

echo "$(mark "${stage1}") Stage 1 lint green — export MINIWEBAPP_LINT_GATE=1 (#539)"
echo "       ${REPO_URL}/issues/539"

echo "$(mark "${stage2}") Stage 2 ServeTest — export MINIWEBAPP_SERVE_GATE=1 (#470)"
echo "       ${REPO_URL}/issues/470"

echo "$(mark "${stage3}") Stage 3 web-smoke — make examples-web-smoke includes 003 (#461)"
echo "       ${REPO_URL}/issues/461"

echo "$(mark "${stage4}") Stage 4 AOT — ExamplesCompileTest @group miniwebapp unskipped (#454)"
echo "       ${REPO_URL}/issues/454"

echo
echo "Commands:"
echo "  make web-smoke              lint + VM smoke (#455)"
echo "  make examples-web-smoke     phpc serve + curl (#298)"
echo "  MINIWEBAPP_LINT_GATE=1 make web-smoke"
echo
echo "Tracking: ${REPO_URL}/issues/472 (gate ladder spec)"

# Current focus
if [[ "${lint_exit}" -ne 0 && "${lint_gate}" != "1" ]]; then
  echo "Next: close lint blockers (#539), then export MINIWEBAPP_LINT_GATE=1"
elif [[ "${lint_exit}" -eq 0 && "${lint_gate}" != "1" ]]; then
  echo "Next: export MINIWEBAPP_LINT_GATE=1 (lint is green)"
elif [[ "${lint_exit}" -ne 0 && "${lint_gate}" == "1" ]]; then
  echo "Next: fix lint regressions (MINIWEBAPP_LINT_GATE=1 is set)"
elif [[ "${serve_gate}" != "1" ]]; then
  echo "Next: export MINIWEBAPP_SERVE_GATE=1 when ServeTest routes land (#470)"
elif [[ "${stage3}" -eq 0 ]]; then
  echo "Next: extend script/examples-web-smoke.sh for 003 (#461)"
elif [[ "${stage4}" -eq 0 ]]; then
  echo "Next: unskip test003MiniWebAppEventuallyRuns in ExamplesCompileTest (#454)"
else
  echo "All documented gates are enabled in this tree."
fi

# Lint JSON blockers when gate enforced or lint still failing
if [[ "${RUN_LINT}" -eq 1 && -s "${LINT_JSON}" && "${lint_exit}" -ne 0 ]]; then
  if [[ "${lint_gate}" == "1" || "${lint_exit}" -ne 0 ]]; then
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
