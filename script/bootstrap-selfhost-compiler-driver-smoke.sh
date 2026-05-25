#!/usr/bin/env bash
# Wrapper: native link + execute compiler_driver_smoke bundle (issue #2136).
set -euo pipefail
exec "$(dirname "$0")/bootstrap-selfhost-compiler-driver-smoke-link.sh" "$@"
