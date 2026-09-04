# User-facing phpc image (#36390 / #36391).
#
# Prefer: ./script/build-phpc-release-image.sh
# Or from a staged/context tree:
#   docker build -f Docker/release/Dockerfile -t ghcr.io/purhur/phpc:dev .
#
# Multi-arch (#36391):
#   PLATFORMS=linux/amd64,linux/arm64 ./script/build-phpc-release-image.sh --push
# Stage both helper-runtime arches; this Dockerfile keeps only TARGETARCH's tree
# so each platform digest stays under the cold-install budget (no 798 MiB wrong-arch corpus).
#
# Run (host needs only Docker):
#   docker run --rm -v "$PWD:/app" -w /app ghcr.io/purhur/phpc:dev build -o hello hello.php
#   ./hello
#
# Base image already has PHP 8.2 + LLVM 9 at /opt/llvm9 (php-compiler:22.04-dev).
ARG BASE_IMAGE=php-compiler:22.04-dev
FROM ${BASE_IMAGE}

ARG TARGETARCH

ENV PHPC_HOME=/opt/phpc
ENV PHP_COMPILER_LLVM_PATH=/opt/llvm9
ENV LD_LIBRARY_PATH=/opt/llvm9
ENV COMPOSER_ALLOW_SUPERUSER=1

# Staged tree from script/build-phpc-release-image.sh (no .git / test / benchmarks).
COPY . ${PHPC_HOME}
WORKDIR ${PHPC_HOME}

# Keep only the helper-runtime arch matching this platform image (#36391).
RUN set -eux; \
  arch="${TARGETARCH:-}"; \
  if [ -z "$arch" ]; then \
    case "$(uname -m)" in \
      x86_64|amd64) arch=amd64 ;; \
      aarch64|arm64) arch=arm64 ;; \
      *) arch=amd64 ;; \
    esac; \
  fi; \
  case "$arch" in \
    amd64|x86_64) keep=x86_64-linux ;; \
    arm64|aarch64) keep=aarch64-linux ;; \
    *) echo "unsupported TARGETARCH=$arch" >&2; exit 1 ;; \
  esac; \
  hr="${PHPC_HOME}/prelinked/helper-runtime"; \
  if [ -d "$hr" ]; then \
    for d in "$hr"/*; do \
      [ -d "$d" ] || continue; \
      base="$(basename "$d")"; \
      if [ "$base" != "$keep" ]; then rm -rf "$d"; fi; \
    done; \
    test -d "$hr/$keep"; \
    echo "phpc-release: helper-runtime kept ${keep} (TARGETARCH=${arch})"; \
  fi

# Lean runtime deps only (~8 MiB). Host vendor/ is intentionally not staged (#36390 ≤1 GB).
RUN composer install --ignore-platform-reqs --no-ansi --no-interaction --no-progress --no-dev \
 && chmod +x phpc script/*.sh Docker/release/entrypoint.sh \
 && ./script/apply-patches.sh \
 && cp Docker/release/entrypoint.sh /usr/local/bin/phpc-entrypoint \
 && chmod +x /usr/local/bin/phpc-entrypoint \
 && test -x phpc \
 && php -r 'require "vendor/autoload.php"; echo "phpc-release: vendor ok\n";'

WORKDIR /app
VOLUME ["/app"]
ENTRYPOINT ["/usr/local/bin/phpc-entrypoint"]
