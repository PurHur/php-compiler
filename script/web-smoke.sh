#!/usr/bin/env bash
# Quick VM smoke for shipped web examples (issues #126, #304).
# 1) phpc lint on every examples/*/example.php
# 2) VM run for 001-SimpleWeb with ?name=Test
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"

shopt -s nullglob
examples=(examples/*/example.php)
if ((${#examples[@]} == 0)); then
  echo "web-smoke: no examples/*/example.php found" >&2
  exit 1
fi
IFS=$'\n' examples=($(printf '%s\n' "${examples[@]}" | sort))
unset IFS

for example in "${examples[@]}"; do
  echo "web-smoke: lint ${example}"
  if ! "$ROOT/phpc" lint "$example"; then
    echo "web-smoke: lint failed for ${example} (see docs/unsupported-syntax.md and issue links in lint output)" >&2
    exit 1
  fi
done

out="$("$ROOT/script/php-local.sh" bin/vm.php -q 'name=Test' examples/001-SimpleWeb/example.php)"
if ! echo "$out" | grep -q 'Hello'; then
  echo "web-smoke: expected output to contain Hello" >&2
  echo "$out" >&2
  exit 1
fi
echo "web-smoke: ok"
