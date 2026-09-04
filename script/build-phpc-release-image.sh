#!/usr/bin/env bash
# Build the user-facing phpc release image (#36390).
#
# Stages a slim tree (no .git / test / benchmarks / aarch64 helpers on x86_64),
# then docker-builds ghcr.io/purhur/phpc:<tag> from Docker/release/Dockerfile.
#
# Usage:
#   ./script/build-phpc-release-image.sh
#   PHPC_RELEASE_TAG=v1.1.0 ./script/build-phpc-release-image.sh
#   PHPC_RELEASE_IMAGE=phpc:local ./script/build-phpc-release-image.sh
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT" || exit 1

: "${PHPC_RELEASE_TAG:=dev}"
: "${PHPC_RELEASE_IMAGE:=ghcr.io/purhur/phpc:${PHPC_RELEASE_TAG}}"
: "${PHPC_RELEASE_BASE_IMAGE:=${PHP_COMPILER_DEV_IMAGE:-php-compiler:22.04-dev}}"
: "${PHPC_RELEASE_KEEP_STAGE:=0}"

ARCH="$(uname -m)"
case "$ARCH" in
  x86_64|amd64) HELPER_ARCH=x86_64-linux ;;
  aarch64|arm64) HELPER_ARCH=aarch64-linux ;;
  *)
    echo "build-phpc-release-image: unsupported arch ${ARCH}" >&2
    exit 1
    ;;
esac

if ! docker image inspect "$PHPC_RELEASE_BASE_IMAGE" >/dev/null 2>&1; then
  echo "build-phpc-release-image: base image missing: ${PHPC_RELEASE_BASE_IMAGE}" >&2
  echo "build-phpc-release-image: run: make docker-build-22" >&2
  exit 1
fi

STAGE="$(mktemp -d "${TMPDIR:-/tmp}/phpc-release-stage.XXXXXX")"
cleanup() {
  if [[ "${PHPC_RELEASE_KEEP_STAGE}" = "1" ]]; then
    echo "build-phpc-release-image: kept stage at ${STAGE}" >&2
  else
    rm -rf "$STAGE"
  fi
}
trap cleanup EXIT

echo "build-phpc-release-image: staging into ${STAGE} (helper arch ${HELPER_ARCH})"

# Core compiler tree — keep this list intentional (Ship path, not a full clone).
for path in \
  bin lib ext script patches phpc composer.json composer.lock \
  src php \
  Docker/release \
  examples/000-HelloWorld \
  docs/GETTING-STARTED.md docs/bootstrap-sdk-platform.md docs/bootstrap-sdk-platform.json \
  README.md LICENSE CONTRIBUTING.md \
  prelinked/helper-runtime/"${HELPER_ARCH}" \
  prelinked/bootstrap-gen0 \
  prelinked/bootstrap-vendor \
  prelinked/README.md
do
  if [[ -e "${REPO_ROOT}/${path}" ]]; then
    mkdir -p "${STAGE}/$(dirname "${path}")"
    cp -a "${REPO_ROOT}/${path}" "${STAGE}/${path}"
  fi
done

# Never stage the host vendor/ tree (often multi-GB with phpunit). The Dockerfile
# runs `composer install --no-dev` (~8 MiB) so the image stays under the 1 GB
# cold-install download budget (#36390).
if [[ "${PHPC_RELEASE_COPY_VENDOR:-0}" = "1" && -f "${REPO_ROOT}/vendor/autoload.php" ]]; then
  echo "build-phpc-release-image: WARNING — copying host vendor/ (PHPC_RELEASE_COPY_VENDOR=1)" >&2
  cp -a "${REPO_ROOT}/vendor" "${STAGE}/vendor"
fi

# Tiny .dockerignore inside the stage (context is the stage root).
# Do NOT exclude *.o — prelinked/helper-runtime/*/units/*/unit.o is the cold-build
# fast path (#24351 / #36390). Excluding them forces a full helper re-emit.
cat > "${STAGE}/.dockerignore" <<'EOF'
**/.git
**/build
EOF

echo "build-phpc-release-image: docker build → ${PHPC_RELEASE_IMAGE}"
# Do not pass HARNESS_DOCKER_RUN_OPTS here — those flags are for `docker run`
# (--cpus is rejected by buildx). Cap build RAM via DOCKER_BUILDKIT if needed.
docker build \
  --build-arg "BASE_IMAGE=${PHPC_RELEASE_BASE_IMAGE}" \
  -f "${STAGE}/Docker/release/Dockerfile" \
  -t "${PHPC_RELEASE_IMAGE}" \
  "${STAGE}"

# Also tag local short name for cold-build-check defaults.
if [[ "${PHPC_RELEASE_IMAGE}" != "phpc:local" ]]; then
  docker tag "${PHPC_RELEASE_IMAGE}" "phpc:local" 2>/dev/null || true
fi

echo "build-phpc-release-image: ok — ${PHPC_RELEASE_IMAGE}"
echo "build-phpc-release-image: try: docker run --rm -v \"\$PWD:/app\" -w /app ${PHPC_RELEASE_IMAGE} build -o hello hello.php"
