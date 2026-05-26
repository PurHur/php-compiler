#!/usr/bin/env bash
# 007-ThrowsWeb deploy + PHPC_DEPLOY_ROOT CGI smoke (issue #2124).
#
# Same as: THROWSWEB_DEPLOY_SMOKE_GATE=1 ./script/deploy-smoke.sh --example 007
#
# Usage:
#   ./script/examples-throwsweb-deploy-smoke.sh
#   make examples-throwsweb-deploy-smoke
#
# Docker:
#   ./script/docker-exec.sh -- \
#     make examples-throwsweb-deploy-smoke
set -euo pipefail

cd "$(dirname "$0")/.."
export THROWSWEB_DEPLOY_SMOKE_GATE=1
exec ./script/deploy-smoke.sh --example 007
