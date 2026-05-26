#!/usr/bin/env bash
# Full PHPC_DEPLOY_ROOT deploy ladder 001–003 + opt-in 005/006/007 (issue #2077).
#
# Same as: make deploy-smoke-all
#          DEPLOY_SMOKE_ALL=1 make deploy-smoke
#
# Usage:
#   ./script/deploy-smoke-all.sh
#   make deploy-smoke-all
#   FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE=1 SESSIONS_WEB_DEPLOY_SMOKE_GATE=1 THROWSWEB_DEPLOY_SMOKE_GATE=1 make deploy-smoke-all
#
# Docker:
#   ./script/docker-exec.sh -- \
#     make deploy-smoke-all
set -euo pipefail

cd "$(dirname "$0")/.."

ROOT="${PWD}"
DEPLOY_SMOKE="${ROOT}/script/deploy-smoke.sh"

run_example() {
  "${DEPLOY_SMOKE}" --example "$1"
}

skip_003() {
  echo "deploy-smoke-all: 003-MiniWebApp: skip (DEPLOY_SMOKE_003_EXECUTE=0 — set DEPLOY_SMOKE_003_EXECUTE=1 or MINIWEBAPP_AOT_EXECUTE_GATE=1 #745, #1530)" >&2
}

skip_005() {
  echo "deploy-smoke-all: 005-SessionsWeb: skip (SESSIONS_WEB_DEPLOY_SMOKE_GATE=0 — set SESSIONS_WEB_DEPLOY_SMOKE_GATE=1; see phpc doctor --gates #1893)" >&2
}

skip_006() {
  echo "deploy-smoke-all: 006-FileUploadWeb: skip (FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE=0 — set FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE=1 or make examples-fileupload-deploy-smoke #2028, #2044)" >&2
}

skip_007() {
  echo "deploy-smoke-all: 007-ThrowsWeb: skip (THROWSWEB_DEPLOY_SMOKE_GATE=0 — set THROWSWEB_DEPLOY_SMOKE_GATE=1 or make examples-throwsweb-deploy-smoke #2124)" >&2
}

echo "deploy-smoke-all: ladder 001–003 (+ 005/006/007 when deploy gates=1; probe: ./phpc doctor --gates)"

run_example 001
run_example 002

if [ "${DEPLOY_SMOKE_003_EXECUTE:-1}" = "1" ]; then
  run_example 003
else
  skip_003
fi

if [ "${SESSIONS_WEB_DEPLOY_SMOKE_GATE:-0}" = "1" ]; then
  run_example 005
else
  skip_005
fi

if [ "${FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE:-0}" = "1" ]; then
  run_example 006
else
  skip_006
fi

if [ "${THROWSWEB_DEPLOY_SMOKE_GATE:-0}" = "1" ]; then
  run_example 007
else
  skip_007
fi

echo "deploy-smoke-all: ok"
