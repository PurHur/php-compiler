#!/usr/bin/env bash
# Build the user-facing phpc release image (#36390 / #36391).
#
# Stages a slim tree (no .git / test / benchmarks). Both Linux helper-runtime
# arches are staged so buildx can keep only TARGETARCH's tree in each platform
# image (multi-arch ghcr.io/purhur/phpc without shipping the wrong-arch 798 MiB
# corpus into every digest).
#
# Usage:
#   ./script/build-phpc-release-image.sh
#   PHPC_RELEASE_TAG=v1.1.0 ./script/build-phpc-release-image.sh
#   PHPC_RELEASE_IMAGE=phpc:local ./script/build-phpc-release-image.sh
#   PLATFORMS=linux/amd64,linux/arm64 ./script/build-phpc-release-image.sh --push
#   ./script/build-phpc-release-image.sh --dry-run
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT" || exit 1

: "${PHPC_RELEASE_TAG:=dev}"
: "${PHPC_RELEASE_IMAGE:=ghcr.io/purhur/phpc:${PHPC_RELEASE_TAG}}"
: "${PHPC_RELEASE_BASE_IMAGE:=${PHP_COMPILER_DEV_IMAGE:-php-compiler:22.04-dev}}"
: "${PHPC_RELEASE_KEEP_STAGE:=0}"
: "${PLATFORMS:=}"

PUSH=0
DRY_RUN=0

usage() {
  cat <<'EOF'
Usage: script/build-phpc-release-image.sh [--push] [--dry-run]

  (default)  docker build host platform → PHPC_RELEASE_IMAGE (+ phpc:local)
  --push     docker buildx build --platform PLATFORMS --push (multi-arch #36391)
  --dry-run  print planned stage + docker commands only

Environment:
  PHPC_RELEASE_TAG / PHPC_RELEASE_IMAGE / PHPC_RELEASE_BASE_IMAGE
  PLATFORMS   e.g. linux/amd64,linux/arm64 (required with --push for multi-arch)
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --push) PUSH=1; shift ;;
    --dry-run) DRY_RUN=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "build-phpc-release-image: unknown argument: $1" >&2; usage >&2; exit 1 ;;
  esac
done

run() {
  if [[ "${DRY_RUN}" -eq 1 ]]; then
    echo "[dry-run] $*"
    return 0
  fi
  "$@"
}

ARCH="$(uname -m)"
case "$ARCH" in
  x86_64|amd64) HOST_HELPER_ARCH=x86_64-linux ;;
  aarch64|arm64) HOST_HELPER_ARCH=aarch64-linux ;;
  *)
    echo "build-phpc-release-image: unsupported arch ${ARCH}" >&2
    exit 1
    ;;
esac

if [[ "${DRY_RUN}" -eq 0 ]] && ! docker image inspect "$PHPC_RELEASE_BASE_IMAGE" >/dev/null 2>&1; then
  echo "build-phpc-release-image: base image missing: ${PHPC_RELEASE_BASE_IMAGE}" >&2
  echo "build-phpc-release-image: run: make docker-build-22" >&2
  exit 1
fi

if [[ "${PUSH}" -eq 1 && -z "${PLATFORMS}" ]]; then
  echo "build-phpc-release-image: --push requires PLATFORMS (e.g. linux/amd64,linux/arm64)" >&2
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

echo "build-phpc-release-image: staging into ${STAGE} (host helper ${HOST_HELPER_ARCH}; both Linux helper arches for TARGETARCH prune)"

# Core compiler tree — keep this list intentional (Ship path, not a full clone).
for path in \
  bin lib ext script patches phpc composer.json composer.lock \
  src php \
  Docker/release \
  examples/000-HelloWorld \
  docs/GETTING-STARTED.md docs/bootstrap-sdk-platform.md docs/bootstrap-sdk-platform.json \
  README.md LICENSE CONTRIBUTING.md \
  prelinked/bootstrap-gen0 \
  prelinked/bootstrap-vendor \
  prelinked/README.md
do
  if [[ -e "${REPO_ROOT}/${path}" ]]; then
    mkdir -p "${STAGE}/$(dirname "${path}")"
    if [[ "${DRY_RUN}" -eq 1 ]]; then
      echo "[dry-run] stage ${path}"
    else
      cp -a "${REPO_ROOT}/${path}" "${STAGE}/${path}"
    fi
  fi
done

# Both Linux helper-runtime trees (#36391). Dockerfile keeps only TARGETARCH's dir.
for helper_arch in x86_64-linux aarch64-linux; do
  src="${REPO_ROOT}/prelinked/helper-runtime/${helper_arch}"
  if [[ -d "$src" ]]; then
    mkdir -p "${STAGE}/prelinked/helper-runtime"
    if [[ "${DRY_RUN}" -eq 1 ]]; then
      echo "[dry-run] stage prelinked/helper-runtime/${helper_arch}"
    else
      cp -a "$src" "${STAGE}/prelinked/helper-runtime/${helper_arch}"
    fi
  else
    echo "build-phpc-release-image: WARNING — missing ${src}" >&2
  fi
done

# Never stage the host vendor/ tree (often multi-GB with phpunit). The Dockerfile
# runs `composer install --no-dev` (~8 MiB) so the image stays under the 1 GB
# cold-install download budget (#36390).
if [[ "${PHPC_RELEASE_COPY_VENDOR:-0}" = "1" && -f "${REPO_ROOT}/vendor/autoload.php" ]]; then
  echo "build-phpc-release-image: WARNING — copying host vendor/ (PHPC_RELEASE_COPY_VENDOR=1)" >&2
  if [[ "${DRY_RUN}" -eq 0 ]]; then
    cp -a "${REPO_ROOT}/vendor" "${STAGE}/vendor"
  fi
fi

# Tiny .dockerignore inside the stage (context is the stage root).
# Do NOT exclude *.o — prelinked/helper-runtime/*/units/*/unit.o is the cold-build
# fast path (#24351 / #36390). Excluding them forces a full helper re-emit.
if [[ "${DRY_RUN}" -eq 0 ]]; then
  cat > "${STAGE}/.dockerignore" <<'EOF'
**/.git
**/build
EOF
fi

if [[ "${PUSH}" -eq 1 ]]; then
  echo "build-phpc-release-image: buildx --platform ${PLATFORMS} --push → ${PHPC_RELEASE_IMAGE}"
  # Do not pass HARNESS_DOCKER_RUN_OPTS — those flags are for `docker run`.
  run docker buildx build \
    --build-arg "BASE_IMAGE=${PHPC_RELEASE_BASE_IMAGE}" \
    -f "${STAGE}/Docker/release/Dockerfile" \
    -t "${PHPC_RELEASE_IMAGE}" \
    --platform "${PLATFORMS}" \
    --push \
    "${STAGE}"
  echo "build-phpc-release-image: published ${PHPC_RELEASE_IMAGE} (${PLATFORMS})"
  exit 0
fi

echo "build-phpc-release-image: docker build → ${PHPC_RELEASE_IMAGE}"
# Do not pass HARNESS_DOCKER_RUN_OPTS here — those flags are for `docker run`
# (--cpus is rejected by buildx). Cap build RAM via DOCKER_BUILDKIT if needed.
run docker build \
  --build-arg "BASE_IMAGE=${PHPC_RELEASE_BASE_IMAGE}" \
  -f "${STAGE}/Docker/release/Dockerfile" \
  -t "${PHPC_RELEASE_IMAGE}" \
  "${STAGE}"

# Also tag local short name for cold-build-check defaults.
if [[ "${DRY_RUN}" -eq 0 && "${PHPC_RELEASE_IMAGE}" != "phpc:local" ]]; then
  docker tag "${PHPC_RELEASE_IMAGE}" "phpc:local" 2>/dev/null || true
fi

echo "build-phpc-release-image: ok — ${PHPC_RELEASE_IMAGE}"
echo "build-phpc-release-image: try: docker run --rm -v \"\$PWD:/app\" -w /app ${PHPC_RELEASE_IMAGE} build -o hello hello.php"
echo "build-phpc-release-image: multi-arch: PLATFORMS=linux/amd64,linux/arm64 $0 --push"
