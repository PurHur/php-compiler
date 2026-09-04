# User-facing phpc image (#36390).
#
# Prefer: ./script/build-phpc-release-image.sh
# Or from a staged/context tree:
#   docker build -f Docker/release/Dockerfile -t ghcr.io/purhur/phpc:dev .
#
# Run (host needs only Docker):
#   docker run --rm -v "$PWD:/app" -w /app ghcr.io/purhur/phpc:dev build -o hello hello.php
#   ./hello
#
# Base image already has PHP 8.2 + LLVM 9 at /opt/llvm9 (php-compiler:22.04-dev).
ARG BASE_IMAGE=php-compiler:22.04-dev
FROM ${BASE_IMAGE}

ENV PHPC_HOME=/opt/phpc
ENV PHP_COMPILER_LLVM_PATH=/opt/llvm9
ENV LD_LIBRARY_PATH=/opt/llvm9
ENV COMPOSER_ALLOW_SUPERUSER=1

# Staged tree from script/build-phpc-release-image.sh (no .git / test / benchmarks).
COPY . ${PHPC_HOME}
WORKDIR ${PHPC_HOME}

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
