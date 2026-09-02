#!/usr/bin/env bash
# Opcode dump corpus gate (#36230) — wrapper around script/opcode-corpus-md5.php.
#
#   ./script/opcode-corpus-md5.sh           # check
#   ./script/opcode-corpus-md5.sh --update  # refresh test/differential/OPCODE-CORPUS.md5
#
# Wired into script/check-generated-docs.sh (fast tier). Empty corpus is a fail.
set -euo pipefail
cd "$(dirname "$0")/.."
PHP_BIN="${PHP_BIN:-php}"
exec "$PHP_BIN" script/opcode-corpus-md5.php "$@"
