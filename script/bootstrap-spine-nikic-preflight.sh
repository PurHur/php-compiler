#!/usr/bin/env bash
# Nikic-parse every PHP file on the self-host spine before multi-hour Zend AOT (#22642).
#
# Catches premature docblock closes (e.g. DateTime*/ ending a comment) and other
# syntax that php -l may miss when the file is only ever reached mid-spine.
#
# Usage:
#   ./script/bootstrap-spine-nikic-preflight.sh
#   source script/php-env.sh && php script/bootstrap-spine-nikic-preflight.php
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT}"

# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"

exec php "${ROOT}/script/bootstrap-spine-nikic-preflight.php" "$@"
