#!/usr/bin/env bash
# Quick VM smoke for examples/001-SimpleWeb (issue #126).
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"
out="$("$ROOT/script/php-local.sh" bin/vm.php -q 'name=Test' examples/001-SimpleWeb/example.php)"
if ! echo "$out" | grep -q 'Hello'; then
  echo "web-smoke: expected output to contain Hello" >&2
  echo "$out" >&2
  exit 1
fi
echo "web-smoke: ok"
