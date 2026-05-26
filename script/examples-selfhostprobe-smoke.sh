#!/usr/bin/env bash
# 008-SelfHostProbe VM lint + run smoke (issue #2240).
#
# Usage:
#   ./script/examples-selfhostprobe-smoke.sh
#   make examples-selfhostprobe-smoke
#
# Docker:
#   ./script/docker-exec.sh -- bash -lc 'source script/php-env.sh && make examples-selfhostprobe-smoke'
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT}"
EXAMPLE="${ROOT}/examples/008-SelfHostProbe/example.php"
PHPC="${ROOT}/phpc"

if [[ ! -f "${EXAMPLE}" ]]; then
  echo "examples-selfhostprobe-smoke: skip — ${EXAMPLE} missing (#2207)" >&2
  exit 0
fi

if [[ -f "${ROOT}/script/php-env.sh" ]]; then
  # shellcheck source=php-env.sh
  source "${ROOT}/script/php-env.sh"
fi

echo "examples-selfhostprobe-smoke: lint ${EXAMPLE}..."
if ! "${PHPC}" lint "${EXAMPLE}"; then
  echo "examples-selfhostprobe-smoke: lint failed" >&2
  exit 1
fi

echo "examples-selfhostprobe-smoke: run ${EXAMPLE}..."
run_out="$("${PHPC}" run "${EXAMPLE}" 2>&1)" || {
  echo "examples-selfhostprobe-smoke: run failed" >&2
  printf '%s\n' "${run_out}" >&2
  exit 1
}
printf '%s\n' "${run_out}"

for needle in 'SelfHostProbe' 'north-star2-verify'; do
  if ! grep -q "${needle}" <<< "${run_out}"; then
    echo "examples-selfhostprobe-smoke: unexpected stdout (want ${needle})" >&2
    exit 1
  fi
done

echo ""
echo "examples-selfhostprobe-smoke: OK"
echo "Next:"
echo "  make north-star2-verify"
echo "  BOOTSTRAP_LIB_SPINE_SMOKE=1 make bootstrap-selfhost-lib-spine-smoke"
echo "  ./phpc doctor --selfhost"
echo "  ./phpc doctor --gates | grep -i bootstrap"
