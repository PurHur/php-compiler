#!/usr/bin/env bash
# Build and optionally push the PHP 8.2 dev image to ghcr.io (issue #202).
# Maintainer-only: requires `docker login ghcr.io` (PAT with write:packages).
# Does not use GitHub Actions — local buildx push only.
#
#   ./script/docker-publish-dev.sh              # build + tag locally (no push)
#   ./script/docker-publish-dev.sh --push       # buildx build --push to registry
#   ./script/docker-publish-dev.sh --dry-run    # print commands only
#
# Tags (override via env):
#   LOCAL_DEV_IMAGE=php-compiler:22.04-dev
#   PHP_COMPILER_DEV_IMAGE=ghcr.io/PurHur/php-compiler:dev
set -euo pipefail
cd "$(dirname "$0")/.."

LOCAL_TAG="${LOCAL_DEV_IMAGE:-php-compiler:22.04-dev}"
REGISTRY_TAG="${PHP_COMPILER_DEV_IMAGE:-ghcr.io/PurHur/php-compiler:dev}"
DOCKERFILE="Docker/dev/ubuntu-22.04/Dockerfile"
PUSH=0
DRY_RUN=0

usage() {
  cat <<'EOF'
Usage: script/docker-publish-dev.sh [--push] [--dry-run]

  (default)  docker build -t LOCAL_DEV_IMAGE -t PHP_COMPILER_DEV_IMAGE
  --push     docker buildx build --push (requires docker login ghcr.io)
  --dry-run  print planned commands, do not run docker

Environment:
  LOCAL_DEV_IMAGE          local tag (default: php-compiler:22.04-dev)
  PHP_COMPILER_DEV_IMAGE   registry tag (default: ghcr.io/PurHur/php-compiler:dev)

Contributors without registry access: make docker-build-22
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --push) PUSH=1; shift ;;
    --dry-run) DRY_RUN=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "docker-publish-dev: unknown argument: $1" >&2; usage >&2; exit 1 ;;
  esac
done

run() {
  if [[ "${DRY_RUN}" -eq 1 ]]; then
    echo "[dry-run] $*"
    return 0
  fi
  "$@"
}

if ! docker info >/dev/null 2>&1; then
  echo "docker-publish-dev: docker info failed — is the daemon running?" >&2
  exit 1
fi

if [[ "${PUSH}" -eq 1 ]]; then
  echo "Publishing ${REGISTRY_TAG} (and ${LOCAL_TAG}) via buildx..."
  cmd=(docker buildx build -f "${DOCKERFILE}"
    -t "${LOCAL_TAG}" -t "${REGISTRY_TAG}"
    --platform linux/amd64
    --push .)
  run "${cmd[@]}"
  echo "Published ${REGISTRY_TAG}"
  echo "Consumers: export PHP_COMPILER_DEV_IMAGE=${REGISTRY_TAG}"
  echo "           docker pull ${REGISTRY_TAG}"
  exit 0
fi

echo "Building dev image (local tags only; use --push to publish)..."
run docker build -f "${DOCKERFILE}" -t "${LOCAL_TAG}" -t "${REGISTRY_TAG}" .
echo "Built ${LOCAL_TAG} and ${REGISTRY_TAG}"
echo "Verify: docker run --rm ${LOCAL_TAG} php -v"
echo "Push:   ./script/docker-publish-dev.sh --push"
