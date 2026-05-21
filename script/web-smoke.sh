#!/usr/bin/env bash
# Quick VM smoke for shipped web examples (issues #126, #304, #455).
# 1) phpc lint on every examples/*/example.php
# 2) phpc lint --all on examples/003-MiniWebApp when public/ exists (lint-first; #455)
# 3) VM run for 001-SimpleWeb with ?name=Test
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"

shopt -s nullglob
examples=(examples/*/example.php)
if ((${#examples[@]} == 0)); then
  echo "web-smoke: no examples/*/example.php found" >&2
  exit 1
fi
IFS=$'\n' examples=($(printf '%s\n' "${examples[@]}" | sort))
unset IFS

for example in "${examples[@]}"; do
  echo "web-smoke: lint ${example}"
  if ! "$ROOT/phpc" lint "$example"; then
    echo "web-smoke: lint failed for ${example} (see docs/unsupported-syntax.md and issue links in lint output)" >&2
    exit 1
  fi
done

MINIWEBAPP=examples/003-MiniWebApp
if [[ -d "${MINIWEBAPP}/public" ]]; then
  LINT_JSON="${TMPDIR:-/tmp}/miniwebapp-lint.json"
  echo "web-smoke: lint --all ${MINIWEBAPP} (see ${MINIWEBAPP}/README.md; JSON -> ${LINT_JSON})"
  lint_stderr="$(mktemp)"
  set +e
  "$ROOT/phpc" lint --all "${MINIWEBAPP}" --json 2>"${lint_stderr}" | tee "${LINT_JSON}"
  miniwebapp_lint_exit=$?
  set -e
  if [[ -s "${lint_stderr}" ]]; then
    cat "${lint_stderr}" >&2
  fi
  rm -f "${lint_stderr}"

  if [[ -s "${LINT_JSON}" ]]; then
    "$ROOT/script/php-local.sh" -r '
$path = $argv[1];
$raw = file_get_contents($path);
$data = json_decode($raw, true);
if (!is_array($data) || !isset($data["issues"]) || !is_array($data["issues"])) {
    fwrite(STDERR, "web-smoke: could not parse lint JSON at {$path}\n");
    exit(0);
}
$issues = $data["issues"];
$count = count($issues);
echo "web-smoke: 003-MiniWebApp: {$count} lint issue(s)\n";
$tracking = [];
foreach ($issues as $issue) {
    if (!empty($issue["issue"])) {
        $tracking[(int) $issue["issue"]] = true;
    }
}
if ($tracking !== []) {
    ksort($tracking);
    $labels = array_map(static fn (int $n): string => "#{$n}", array_keys($tracking));
    echo "web-smoke: tracking issues: ".implode(", ", $labels)."\n";
}
$shown = 0;
foreach ($issues as $issue) {
    if ($shown >= 8) {
        $rest = $count - $shown;
        if ($rest > 0) {
            echo "web-smoke:   … and {$rest} more (see {$path})\n";
        }
        break;
    }
    $file = $issue["file"] ?? "?";
    $line = $issue["line"] ?? 0;
    $kind = $issue["kind"] ?? "?";
    $gh = !empty($issue["issue"]) ? " (#{$issue["issue"]})" : "";
    echo "web-smoke:   {$file}:{$line}: {$kind}{$gh}\n";
    ++$shown;
}
' "${LINT_JSON}"
  fi

  if [[ "${MINIWEBAPP_LINT_GATE:-}" == "1" ]]; then
    if [[ "${miniwebapp_lint_exit}" -ne 0 ]]; then
      echo "web-smoke: 003-MiniWebApp lint gate failed (MINIWEBAPP_LINT_GATE=1; expected green after #67)" >&2
      exit 1
    fi
    echo "web-smoke: 003-MiniWebApp lint gate ok"
  elif [[ "${miniwebapp_lint_exit}" -ne 0 ]]; then
    echo "web-smoke: 003-MiniWebApp lint exit ${miniwebapp_lint_exit} (skeleton — not failing web-smoke; set MINIWEBAPP_LINT_GATE=1 when #67 is green)"
  else
    echo "web-smoke: 003-MiniWebApp lint green"
  fi
fi

out="$("$ROOT/script/php-local.sh" bin/vm.php -q 'name=Test' examples/001-SimpleWeb/example.php)"
if ! echo "$out" | grep -q 'Hello'; then
  echo "web-smoke: expected output to contain Hello" >&2
  echo "$out" >&2
  exit 1
fi
echo "web-smoke: ok"
