#!/usr/bin/env bash
# Regenerate docs/capabilities.md inside php:8.2-cli-bookworm and copy back via docker cp.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
IMAGE="${PHP_COMPILER_DOCKER_IMAGE:-php:8.2-cli-bookworm}"
DOCKER_EXTRA=()
if [[ -n "${PHP_COMPILER_DOCKER_RUN_OPTS:-${HARNESS_DOCKER_RUN_OPTS:-}}" ]]; then
  # shellcheck disable=SC2206
  DOCKER_EXTRA=(${PHP_COMPILER_DOCKER_RUN_OPTS:-${HARNESS_DOCKER_RUN_OPTS}})
fi

CID=$(docker create "${DOCKER_EXTRA[@]}" "$IMAGE" bash -lc 'mkdir -p /compiler; exec sleep 600')
cleanup() { docker rm -f "$CID" >/dev/null 2>&1 || true; }
trap cleanup EXIT
docker start "$CID" >/dev/null

tar -C "$ROOT" -cf - --exclude='.git' --exclude='.phpunit.result.cache' . | docker exec -i "$CID" bash -lc 'tar xf - -C /compiler'

docker exec "$CID" bash -lc '
set -e
cd /compiler
apt-get update -qq
DEBIAN_FRONTEND=noninteractive apt-get install -y -qq pkg-config libffi-dev unzip curl > /dev/null
docker-php-ext-install -j"$(nproc)" ffi > /dev/null 2>&1
if ! command -v composer >/dev/null 2>&1; then
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi
composer install --no-interaction --ignore-platform-reqs > /dev/null 2>&1
chmod +x script/*.sh
script/apply-patches.sh > /dev/null 2>&1 || true
php script/capability-matrix.php
'
docker cp "$CID:/compiler/docs/capabilities.md" "$ROOT/docs/capabilities.md"
echo "Updated $ROOT/docs/capabilities.md"
