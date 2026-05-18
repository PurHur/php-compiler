#!/usr/bin/env bash
# Run script/ci-local.sh inside php:8.2-cli-bookworm when the host cannot bind-mount the repo.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
IMAGE="${PHP_COMPILER_DOCKER_IMAGE:-php:8.2-cli-bookworm}"
EXTRA_PHPUNIT_ARGS=("$@")

cd "$ROOT"
tar cf - --exclude='.git' . | docker run -i --rm "$IMAGE" bash -lc '
set -e
mkdir -p /compiler && tar xf - -C /compiler
cd /compiler
apt-get update -qq
DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
  dpkg-dev pkg-config libffi-dev build-essential unzip git curl > /dev/null
docker-php-ext-install -j"$(nproc)" ffi > /dev/null 2>&1
if ! command -v composer >/dev/null 2>&1; then
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi
chmod +x script/*.sh
export LD_LIBRARY_PATH=/compiler/.llvm
export PATH=/compiler/.llvm:$PATH
export PHP_COMPILER_LLVM_PATH=/compiler/.llvm
exec ./script/ci-local.sh '"$(printf '%q ' "${EXTRA_PHPUNIT_ARGS[@]}")"
