#!/usr/bin/env bash
# Recreate Slim+nyholm hello fixture for #36382 Done-when (not committed: vendor/ is gitignored).
# Default composer_closure=reachable keeps the ProjectGraph to AutoloadDiscovery hits (~94 files)
# instead of dumping every PSR-4 path (~134). Full AOT uses incremental IncludeHelper requires
# when unit count >= 32 (SourceBundler mega-concat OOMs on 8g hosts — #36382).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
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
/**
 * #36382 — Slim hello via `$app->handle` + CGI emit (not App::run()).
 * AutoloadDiscovery walks every method CFG; App::run() pulls ServerRequestCreator
 * / ResponseEmitter / SlimHttp* into the graph and stalls full AOT. Handle+emit
 * keeps the Done-when /hello body under `phpc fcgi` / CGI env without that stack.
 */
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

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/hello';
$request = $psr17->createServerRequest($method, $uri);
$response = $app->handle($request);
http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header($name . ': ' . $value, false);
    }
}
echo (string) $response->getBody();
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

URI="$DEST/vendor/nyholm/psr7/src/Uri.php"
if [[ -f "$URI" ]]; then
  php "$ROOT/script/composer/patch-nyholm-uri-construct-36382.php" "$URI"
fi

STREAM="$DEST/vendor/nyholm/psr7/src/Stream.php"
if [[ -f "$STREAM" ]]; then
  php "$ROOT/script/composer/patch-nyholm-stream-36382.php" "$STREAM"
fi

STREAM_TRAIT="$DEST/vendor/nyholm/psr7/src/StreamTrait.php"
if [[ -f "$STREAM_TRAIT" ]]; then
  php "$ROOT/script/composer/patch-nyholm-stream-trait-36382.php" "$STREAM_TRAIT"
fi

REQ_TRAIT="$DEST/vendor/nyholm/psr7/src/RequestTrait.php"
if [[ -f "$REQ_TRAIT" ]]; then
  php "$ROOT/script/composer/patch-nyholm-request-trait-36382.php" "$REQ_TRAIT"
fi

NY_SRC="$DEST/vendor/nyholm/psr7-server/src/ServerRequestCreator.php"
if [[ -f "$NY_SRC" ]]; then
  php "$ROOT/script/composer/patch-nyholm-get-headers-from-server-36382.php" "$NY_SRC"
fi

RC="$DEST/vendor/slim/slim/Slim/Routing/RouteCollector.php"
if [[ -f "$RC" ]]; then
  php "$ROOT/script/composer/patch-slim-route-collector-36382.php" "$RC"
  php "$ROOT/script/composer/patch-slim-fastroute-rows-36382.php" "$RC"
fi

RR="$DEST/vendor/slim/slim/Slim/Routing/RouteResolver.php"
if [[ -f "$RR" ]]; then
  php "$ROOT/script/composer/patch-slim-route-resolver-36382.php" "$RR"
fi

DISP="$DEST/vendor/slim/slim/Slim/Routing/Dispatcher.php"
if [[ -f "$DISP" ]]; then
  php "$ROOT/script/composer/patch-slim-dispatcher-closure-36382.php" "$DISP"
  php "$ROOT/script/composer/patch-slim-dispatcher-static-map-36382.php" "$DISP"
fi

FRD="$DEST/vendor/slim/slim/Slim/Routing/FastRouteDispatcher.php"
if [[ -f "$FRD" ]]; then
  php "$ROOT/script/composer/patch-slim-fastroute-dispatcher-return-36382.php" "$FRD"
  php "$ROOT/script/composer/patch-slim-fastroute-dispatcher-isset-36382.php" "$FRD"
fi

RRR="$DEST/vendor/slim/slim/Slim/Routing/RoutingResults.php"
if [[ -f "$RRR" ]]; then
  php "$ROOT/script/composer/patch-slim-routing-results-return-36382.php" "$RRR"
fi

RCP="$DEST/vendor/slim/slim/Slim/Routing/RouteCollectorProxy.php"
if [[ -f "$RCP" ]]; then
  php "$ROOT/script/composer/patch-slim-route-collector-proxy-36382.php" "$RCP"
fi

APP="$DEST/vendor/slim/slim/Slim/App.php"
if [[ -f "$APP" ]]; then
  php "$ROOT/script/composer/patch-slim-app-36382.php" "$APP"
  php "$ROOT/script/composer/patch-slim-app-strip-unused-mw-36382.php" "$APP"
  php "$ROOT/script/composer/patch-slim-app-strip-run-36382.php" "$APP"
fi

AF="$DEST/vendor/slim/slim/Slim/Factory/AppFactory.php"
if [[ -f "$AF" ]]; then
  php "$ROOT/script/composer/patch-slim-appfactory-36382.php" "$AF"
  php "$ROOT/script/composer/patch-slim-appfactory-strip-probe-36382.php" "$AF"
fi

NYF="$DEST/vendor/slim/slim/Slim/Factory/Psr17/NyholmPsr17Factory.php"
if [[ -f "$NYF" ]]; then
  php "$ROOT/script/composer/patch-slim-nyholm-psr17-factory-36382.php" "$NYF"
fi

SHC="$DEST/vendor/slim/slim/Slim/Factory/Psr17/SlimHttpServerRequestCreator.php"
if [[ -f "$SHC" ]]; then
  php "$ROOT/script/composer/patch-slim-http-src-decorator-36382.php" "$SHC"
fi

SRCF="$DEST/vendor/slim/slim/Slim/Factory/ServerRequestCreatorFactory.php"
if [[ -f "$SRCF" ]]; then
  php "$ROOT/script/composer/patch-slim-server-request-creator-factory-36382.php" "$SRCF"
fi

FR="$DEST/vendor/nikic/fast-route/src/functions.php"
if [[ -f "$FR" ]]; then
  php "$ROOT/script/composer/patch-fastroute-options-plus-36382.php" "$FR"
fi

FRC="$DEST/vendor/nikic/fast-route/src/RouteCollector.php"
if [[ -f "$FRC" ]]; then
  php "$ROOT/script/composer/patch-fastroute-array-cast-36382.php" "$FRC"
fi

FRG="$DEST/vendor/nikic/fast-route/src/Dispatcher/GroupCountBased.php"
if [[ -f "$FRG" ]]; then
  php "$ROOT/script/composer/patch-fastroute-groupcount-ctor-36382.php" "$FRG"
fi

echo "Created $DEST ($(find "$DEST" -name '*.php' | wc -l) php files)"
echo "Try: ./phpc build --project $DEST --dry-run"
echo "Note: reachable handle+emit graph ~73 files (run()/ServerRequest* stripped; FastRoute (array) cast avoided); AOT uses incremental requires (>=32 units) — see #36382"
