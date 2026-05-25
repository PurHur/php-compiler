#!/usr/bin/env bash
# 006-FileUploadWeb deploy + PHPC_DEPLOY_ROOT CGI smoke (issue #2044).
#
# Same as: FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE=1 ./script/deploy-smoke.sh --example 006
#
# Usage:
#   ./script/examples-fileupload-deploy-smoke.sh
#   make examples-fileupload-deploy-smoke
#
# Docker:
#   docker run --rm -v "$(pwd):/compiler" -w /compiler php-compiler:22.04-dev \
#     make examples-fileupload-deploy-smoke
set -euo pipefail

cd "$(dirname "$0")/.."
export FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE=1
exec ./script/deploy-smoke.sh --example 006
