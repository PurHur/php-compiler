#!/usr/bin/env bash
# Fast gate-honesty probes — an empty result set must not read as green (#36248).
#
#   ./script/check-gate-honesty.sh
#   ./script/docker-exec.sh -- bash -lc './script/check-gate-honesty.sh'
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

fail=0

step() {
  local name="$1"; shift
  if "$@"; then
    echo "check-gate-honesty: OK   $name"
  else
    echo "check-gate-honesty: FAIL $name" >&2
    fail=1
  fi
}

tmp="$ROOT/build/gate-honesty-tmp"
rm -rf "$tmp"
mkdir -p "$tmp"
trap 'rm -rf "$tmp"' EXIT

# differential-sweep: missing dir and empty corpus must not exit 0
step "differential-sweep missing --dir" bash -c '
  set -euo pipefail
  out="$(./script/differential-sweep.sh --dir /nonexistent/differential-corpus 2>&1)" || ec=$?
  ec="${ec:-0}"
  [[ "$ec" -ne 0 ]] || exit 1
  [[ "$out" == *"no such directory"* ]] || exit 1
'

mkdir -p "$tmp/empty-corpus"
step "differential-sweep empty corpus" bash -c "
  set -euo pipefail
  out=\"\$(./script/differential-sweep.sh --dir '$tmp/empty-corpus' 2>&1)\" || ec=\$?
  ec=\"\${ec:-0}\"
  [[ \"\$ec\" -eq 2 ]] || exit 1
  [[ \"\$out\" == *'empty corpus is not a pass'* ]] || exit 1
"

# opcode-corpus-md5: empty glob must not exit 0 (#36230 / #36248)
step "opcode-corpus-md5 empty corpus" bash -c "
  set -euo pipefail
  mkdir -p '$tmp/opcode-empty'
  # Point the PHP worker at an empty glob by running from a throwaway copy is heavy;
  # instead invoke php with a one-shot override via env consumed by the script.
  out=\"\$(OPCODE_CORPUS_GLOB_OVERRIDE='$tmp/opcode-empty/*.php' php script/opcode-corpus-md5.php --check 2>&1)\" || ec=\$?
  ec=\"\${ec:-0}\"
  [[ \"\$ec\" -ne 0 ]] || exit 1
  [[ \"\$out\" == *'empty corpus is not a pass'* ]] || exit 1
"

# check-stale-issue-refs must fail when rg is absent (Dockerfile should ship rg)
step "check-stale-issue-refs requires rg" bash -c '
  set -euo pipefail
  out="$(PATH=/usr/bin:/bin ./script/check-stale-issue-refs.sh 2>&1)" || ec=$?
  ec="${ec:-0}"
  [[ "$ec" -eq 2 ]] || exit 1
  [[ "$out" == *"ripgrep (rg) required"* ]] || exit 1
'

# Budget: or-true in ci-*.sh + check-*.sh must stay ≤ 20 with #36248 justified comments.
step "ci/check or-true budget ≤ 20" bash -c '
  set -euo pipefail
  # Count executable or-true only (skip comment-only lines).
  # Empty grep is fine — count 0 (#36248 justified).
  mapfile -t hits < <(grep -nE "\|\| true" script/ci-*.sh script/check-*.sh 2>/dev/null | grep -vE "^[^:]+:[0-9]+:[[:space:]]*#" || true)
  count="${#hits[@]}"
  if (( count > 20 )); then
    echo "check-gate-honesty: or-true count ${count} exceeds budget 20 (#36248):" >&2
    printf "%s\n" "${hits[@]}" >&2
    exit 1
  fi
  unjustified=0
  for hit in "${hits[@]}"; do
    file="${hit%%:*}"
    rest="${hit#*:}"
    line_no="${rest%%:*}"
    # Accept a justification on the same line or within the 3 preceding lines.
    start=$(( line_no > 3 ? line_no - 3 : 1 ))
    ctx="$(sed -n "${start},${line_no}p" "$file")"
    if ! grep -qE "#36248 justified" <<<"$ctx"; then
      echo "check-gate-honesty: unjustified or-true at ${hit}" >&2
      unjustified=1
    fi
  done
  [[ "$unjustified" -eq 0 ]] || exit 1
  echo "check-gate-honesty: or-true budget ${count}/20"
'

if [[ "$fail" -ne 0 ]]; then
  exit 1
fi
echo "check-gate-honesty: all probes passed."
