#!/usr/bin/env bash
# Fail when closed GitHub issues still appear as active blockers in tracked paths (issue #802).
#
# Opt-out on a matching line:  # stale-issue-ok: <reason>
#
# Usage:
#   ./script/check-stale-issue-refs.sh
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_ROOT"

# Closed issues that must not be cited as open blockers.
CLOSED_ISSUES=(568 67 764)

SCAN_PATHS=(
  script
  lib/Cli
  examples
  docs/deploy-web-aot.md
)

fail=0

line_is_exempt() {
  local line="$1"
  [[ "$line" == *"stale-issue-ok:"* ]]
}

line_is_closed_mention() {
  local line="$1"
  local issue="$2"
  # Historical "skeleton #67 closed" / "#568 closed" are not active blockers.
  if [[ "$line" == *"#${issue} closed"* || "$line" == *"closed (#${issue}"* || "$line" == *"#${issue}) closed"* ]]; then
    return 0
  fi
  if [[ "$line" == *"[#${issue}]("*"closed"* ]]; then
    return 0
  fi
  return 1
}

check_pattern() {
  local issue="$1"
  local pattern="$2"
  local hits
  if ! hits="$(rg -n --no-heading -S "$pattern" "${SCAN_PATHS[@]}" 2>/dev/null || true)"; then
    hits=""
  fi
  [[ -n "$hits" ]] || return 0
  while IFS= read -r hit; do
    [[ -n "$hit" ]] || continue
    local file="${hit%%:*}"
    local rest="${hit#*:}"
    local line_no="${rest%%:*}"
    local text="${rest#*:}"
    if line_is_exempt "$text"; then
      continue
    fi
    if line_is_closed_mention "$text" "$issue"; then
      continue
    fi
    echo "check-stale-issue-refs: stale blocker for closed #${issue}: ${file}:${line_no}: ${text}" >&2
    fail=1
  done <<<"$hits"
}

for issue in "${CLOSED_ISSUES[@]}"; do
  check_pattern "$issue" "blocked #${issue}"
  check_pattern "$issue" "blocked.*#${issue}"
  check_pattern "$issue" "until #${issue}"
  check_pattern "$issue" "not yet linkable.*#${issue}"
  check_pattern "$issue" "skip until.*#${issue}"
  check_pattern "$issue" "skipped \(#${issue}\)"
  check_pattern "$issue" "003 skipped #${issue}"
done

if [[ "$fail" -ne 0 ]]; then
  echo "check-stale-issue-refs: FAILED — replace blocker copy with open issues (e.g. #764 for AOT execute) or add stale-issue-ok on intentional history." >&2
  exit 1
fi

echo "check-stale-issue-refs: OK (closed issues ${CLOSED_ISSUES[*]} have no stale blocker strings in tracked paths)."
