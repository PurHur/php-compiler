#!/usr/bin/env bash
# Recreate Slim+nyholm hello fixture for #36382 Done-when (not committed: vendor/ is gitignored).
# Default composer_closure=reachable keeps the ProjectGraph to AutoloadDiscovery hits (~94 files)
# instead of dumping every PSR-4 path (~134). Full AOT uses incremental IncludeHelper requires
# when unit count >= 32 (SourceBundler mega-concat OOMs on 8g hosts — #36382).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEST="${1:-$ROOT/test/fixtures/aot/projects/slim_hello_36382}"
rm -rf "$DEST"
mkdir -p "$DEST/public"
cat >"$DEST/composer.json" <<'EOF'
{
  "require": {
    "slim/slim": "^4.13",
    "nyholm/psr7": "^1.8",
    "nyholm/psr7-server": "^1.1"
  }
}
EOF
cat >"$DEST/phpc.json" <<'EOF'
{
  "entry": "public/index.php",
  "binary": ".phpc/bin/slim-hello",
  "autoload": "composer"
}
EOF
cat >"$DEST/public/index.php" <<'EOF'
<?php
use Slim\Factory\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;

require __DIR__ . '/../vendor/autoload.php';

$psr17 = new Psr17Factory();
AppFactory::setResponseFactory($psr17);
$app = AppFactory::create();
$app->get('/hello', function ($request, $response, $args) {
    $response->getBody()->write('hello');
    return $response;
});
$app->run();
EOF
(cd "$DEST" && composer install --no-interaction --no-progress)

# AOT (#36382): Slim ServerRequestCreator uses Closure::fromCallable([$obj, $runtimeMethod]).
# That form is not lowered yet (Call\ClosureFromCallable needs a compile-time string).
# Nyholm always passes method "fromGlobals" — rewrite to a direct call (Zend-equivalent).
SRC_CREATOR="$DEST/vendor/slim/slim/Slim/Factory/Psr17/ServerRequestCreator.php"
if [[ -f "$SRC_CREATOR" ]] && grep -q 'Closure::fromCallable($callable)' "$SRC_CREATOR"; then
  python3 - "$SRC_CREATOR" <<'PY'
import sys
from pathlib import Path
p = Path(sys.argv[1])
text = p.read_text()
old = (
    "        /** @var callable $callable */\n"
    "        $callable = [$this->serverRequestCreator, $this->serverRequestCreatorMethod];\n"
    "\n"
    "        /** @var ServerRequestInterface */\n"
    "        return (Closure::fromCallable($callable))();"
)
new = (
    "        // AOT (#36382): Closure::fromCallable([$obj, $runtimeMethod]) not lowered yet.\n"
    "        // Nyholm always passes method \"fromGlobals\" — call it directly (Zend-equivalent).\n"
    "        /** @var ServerRequestInterface */\n"
    "        return $this->serverRequestCreator->fromGlobals();"
)
if old not in text:
    raise SystemExit("ServerRequestCreator fromCallable pattern not found")
p.write_text(text.replace(old, new, 1))
print("patched ServerRequestCreator for AOT (#36382)")
PY
fi

STREAM="$DEST/vendor/nyholm/psr7/src/Stream.php"
if [[ -f "$STREAM" ]]; then
  php "$ROOT/script/patch-nyholm-stream-36382.php" "$STREAM"
fi

echo "Created $DEST ($(find "$DEST" -name '*.php' | wc -l) php files)"
echo "Try: ./phpc build --project $DEST --dry-run"
echo "Note: reachable graph ~94 files; AOT uses incremental requires (>=32 units) — see #36382"
