#!/usr/bin/env bash
# Run script/ci-local.sh inside php:8.2-cli-bookworm when the host cannot bind-mount the repo.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
IMAGE="${PHP_COMPILER_DOCKER_IMAGE:-php:8.2-cli-bookworm}"
EXTRA_PHPUNIT_ARGS=("$@")

cd "$ROOT"

# shellcheck source=ci-docker-run.sh
source "$(dirname "$0")/ci-docker-run.sh"

quoted_args=$(printf '%q ' "${EXTRA_PHPUNIT_ARGS[@]}")
tar cf - --exclude='.git' . | ci_docker_run -i -w /compiler "$IMAGE" bash -lc "
set -euo pipefail
mkdir -p /compiler && tar xf - -C /compiler
cd /compiler
apt-get update -qq
DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \\
  build-essential dpkg-dev pkg-config libffi-dev libbsd0 unzip git curl > /dev/null
docker-php-ext-install -j\"\\\$(nproc)\" ffi > /dev/null 2>&1
if ! command -v composer >/dev/null 2>&1; then
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi
chmod +x script/*.sh
export LD_LIBRARY_PATH=/compiler/.llvm
export PATH=/compiler/.llvm:\\\$PATH
export PHP_COMPILER_LLVM_PATH=/compiler/.llvm
exec ./script/ci-local.sh ${quoted_args}
"
