#!/usr/bin/env bash
# 009-FastCGIWeb deploy + PHPC_DEPLOY_ROOT CGI smoke (issue #2359).
#
# Same as: FASTCGI_WEB_DEPLOY_SMOKE_GATE=1 ./script/deploy-smoke.sh --example 009
#
# Usage:
#   ./script/examples-fastcgiweb-deploy-smoke.sh
#   make examples-fastcgiweb-deploy-smoke
#
# Docker:
#   ./script/docker-exec.sh -- \
#     make examples-fastcgiweb-deploy-smoke
set -euo pipefail

cd "$(dirname "$0")/.."
export FASTCGI_WEB_DEPLOY_SMOKE_GATE=1
exec ./script/deploy-smoke.sh --example 009
