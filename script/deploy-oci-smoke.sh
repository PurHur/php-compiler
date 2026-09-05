#!/usr/bin/env bash
# phpc deploy --oci structure smoke (#36392).
#
# Builds a minimal fake AOT dist (no LLVM), runs ProjectDeploy::writeOciBundle /
# `phpc deploy --oci`, and asserts Dockerfile / nginx FastCGI recipe / compose
# files exist and look honest (FROM scratch, fastcgi_pass, listen 0.0.0.0:9000).
# Optional: nginx -t when nginx is on PATH (warn-only if fastcgi_params missing).
#
# Usage:
#   ./script/deploy-oci-smoke.sh
#   ./script/docker-exec.sh -- ./script/deploy-oci-smoke.sh
set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$PWD"

if [[ ! -f "$ROOT/vendor/autoload.php" ]]; then
  echo "deploy-oci-smoke: run composer install first" >&2
  exit 1
fi

SMOKE_ROOT="${ROOT}/.phpc/smoke/deploy-oci"
rm -rf "$SMOKE_ROOT"
mkdir -p "$SMOKE_ROOT/project/.phpc/bin" "$SMOKE_ROOT/project/public" "$SMOKE_ROOT/project/assets"

printf '%s\n' '#!/bin/sh' 'echo oci-smoke' >"$SMOKE_ROOT/project/.phpc/bin/app"
chmod 755 "$SMOKE_ROOT/project/.phpc/bin/app"
printf '%s\n' '<?php echo "ok";' >"$SMOKE_ROOT/project/public/index.php"
printf '%s\n' 'body{}' >"$SMOKE_ROOT/project/assets/app.css"
cat >"$SMOKE_ROOT/project/phpc.json" <<'JSON'
{
  "entry": "public/index.php",
  "binary": ".phpc/bin/app",
  "public": "public",
  "assets": "assets"
}
JSON

DIST="$SMOKE_ROOT/dist"
mkdir -p "$DIST"

SMOKE_PROJECT="$SMOKE_ROOT/project" SMOKE_DIST="$DIST" php -d memory_limit=256M <<'PHP'
<?php
require 'vendor/autoload.php';
use PHPCompiler\Web\ProjectDeploy;
$project = getenv('SMOKE_PROJECT');
$dist = getenv('SMOKE_DIST');
$errors = ProjectDeploy::deploy($project, $dist, false);
if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}
$errors = ProjectDeploy::writeOciBundle($dist);
if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}
echo "deploy-oci-smoke: writeOciBundle ok\n";
PHP

for f in Dockerfile Dockerfile.fcgi docker-compose.fcgi.yml nginx/fastcgi.conf README.oci; do
  if [[ ! -f "$DIST/$f" ]]; then
    echo "deploy-oci-smoke: missing $f" >&2
    exit 1
  fi
done

grep -q 'FROM scratch' "$DIST/Dockerfile" || { echo "deploy-oci-smoke: Dockerfile missing FROM scratch" >&2; exit 1; }
grep -q 'ENTRYPOINT \["/app/bin/app"\]' "$DIST/Dockerfile" || { echo "deploy-oci-smoke: scratch ENTRYPOINT" >&2; exit 1; }
grep -q 'COPY public/' "$DIST/Dockerfile" || { echo "deploy-oci-smoke: expected COPY public/" >&2; exit 1; }
grep -q 'phpc' "$DIST/Dockerfile.fcgi" && grep -q 'fcgi' "$DIST/Dockerfile.fcgi" || {
  echo "deploy-oci-smoke: Dockerfile.fcgi missing phpc fcgi" >&2
  exit 1
}
grep -q '0.0.0.0:9000' "$DIST/Dockerfile.fcgi" || { echo "deploy-oci-smoke: Dockerfile.fcgi missing listen 9000" >&2; exit 1; }
grep -q 'fastcgi_pass fcgi:9000' "$DIST/nginx/fastcgi.conf" || { echo "deploy-oci-smoke: nginx fastcgi_pass" >&2; exit 1; }
grep -q 'dockerfile: Dockerfile.fcgi' "$DIST/docker-compose.fcgi.yml" || { echo "deploy-oci-smoke: compose fcgi build" >&2; exit 1; }

CLI_DIST="$SMOKE_ROOT/cli-dist"
rm -rf "$CLI_DIST"
./phpc deploy "$SMOKE_ROOT/project" -o "$CLI_DIST" --oci --from-build
test -f "$CLI_DIST/Dockerfile.fcgi"
test -f "$CLI_DIST/nginx/fastcgi.conf"
echo "deploy-oci-smoke: phpc deploy --oci ok"

if command -v nginx >/dev/null 2>&1; then
  TMP_CONF="$SMOKE_ROOT/nginx-t.conf"
  cat >"$TMP_CONF" <<EOF
events {}
http {
  include $DIST/nginx/fastcgi.conf;
}
EOF
  if nginx -t -c "$TMP_CONF" >/dev/null 2>"$SMOKE_ROOT/nginx-t.err"; then
    echo "deploy-oci-smoke: nginx -t ok"
  else
    echo "deploy-oci-smoke: nginx -t warn (often missing fastcgi_params on bare hosts):" >&2
    cat "$SMOKE_ROOT/nginx-t.err" >&2 || true
  fi
else
  echo "deploy-oci-smoke: nginx not installed — structure checks only"
fi

echo "deploy-oci-smoke: OK"
