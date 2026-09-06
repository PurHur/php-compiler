#!/usr/bin/env bash
# Recreate FastRoute-only dispatch fixture for #36382 (vendor/ is gitignored).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
DEST="${1:-$ROOT/test/fixtures/aot/projects/fastroute_dispatch_36382}"
rm -rf "$DEST"
mkdir -p "$DEST/public"
cat >"$DEST/composer.json" <<'EOF'
{
  "require": {
    "nikic/fast-route": "^1.3"
  }
}
EOF
cat >"$DEST/phpc.json" <<'EOF'
{
  "entry": "public/index.php",
  "binary": ".phpc/bin/fastroute-dispatch",
  "autoload": "composer"
}
EOF
cat >"$DEST/public/index.php" <<'EOF'
<?php
/**
 * #36382 — FastRoute simpleDispatcher under IncludeHelper / project AOT.
 * Must print START then 1:hello_id / OK (not abort after START).
 */
use function FastRoute\simpleDispatcher;

require __DIR__ . '/../vendor/autoload.php';

echo "START\n";

$dispatcher = simpleDispatcher(function ($r) {
    $r->addRoute('GET', '/hello', 'hello_id');
});
$res = $dispatcher->dispatch('GET', '/hello');
echo $res[0], ':', $res[1], "\n";
echo "OK\n";
EOF
(cd "$DEST" && composer install --no-interaction --no-progress)
FR="$DEST/vendor/nikic/fast-route/src/functions.php"
if [[ -f "$FR" ]]; then
  php "$ROOT/script/composer/patch-fastroute-options-plus-36382.php" "$FR"
fi
echo "Created $DEST"
echo "Try: ./phpc build --project $DEST && $DEST/.phpc/bin/fastroute-dispatch"
