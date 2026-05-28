#!/usr/bin/env bash
# M4 alias for full-revision argv bin/compile.php probe (#2880, #1498).
exec "$(cd "$(dirname "$0")" && pwd)/bootstrap-selfhost-full-revision-probe.sh" "$@"
