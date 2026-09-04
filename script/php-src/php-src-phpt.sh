#!/usr/bin/env bash
# php-src .phpt corpus runner (#36381).
# Nested under script/php-src/ so the top-level script/ file-count budget (#36403) stays honest.
#
#   script/php-src/php-src-phpt.sh --corpus=sample --backend=vm
#   script/php-src/php-src-phpt.sh --corpus=sample --backend=vm --collect
#   script/php-src/php-src-phpt.sh --corpus=sample --backend=vm --diff
#   script/php-src/php-src-phpt.sh --php-src=/path/to/php-src --dirs=Zend/tests --backend=vm --shards=24 --shard=0
set -uo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT" || exit 1
# shellcheck disable=SC1091
source script/php-env.sh 2>/dev/null || true
: "${PHP_BIN:=php}"
exec "$PHP_BIN" "$REPO_ROOT/script/php-src/php-src-phpt.php" "$@"
