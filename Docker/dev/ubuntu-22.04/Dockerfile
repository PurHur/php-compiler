FROM ubuntu:22.04

# The host PHP the compiler runs ON, and the interpreter every compliance case is differentially
# compared against. Overridable so an upgrade can be built and measured before it is adopted:
#   docker build --build-arg PHP_VERSION=8.4 -f Docker/dev/ubuntu-22.04/Dockerfile -t php-compiler:8.4-dev .
ARG PHP_VERSION=8.2

ENV DEBIAN_FRONTEND=noninteractive
ENV PHP_COMPILER_PHP=php
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PHP_CS_FIXER_IGNORE_ENV=true

RUN apt-get update && apt-get install -y --no-install-recommends \
    software-properties-common gnupg \
    && add-apt-repository -y ppa:ondrej/php \
    && apt-get update && apt-get install -y --no-install-recommends \
    git curl unzip ca-certificates dpkg \
    php${PHP_VERSION}-cli php${PHP_VERSION}-mbstring php${PHP_VERSION}-xml \
    php${PHP_VERSION}-ffi php${PHP_VERSION}-posix php${PHP_VERSION}-phar \
    php${PHP_VERSION}-tokenizer php${PHP_VERSION}-dom php${PHP_VERSION}-xmlwriter \
    build-essential clang llvm-14-dev libjansson4 libpcre2-dev zlib1g-dev \
    libfreetype6 fonts-dejavu-core \
    libpq5 \
    libodbc2 \
    unixodbc \
    libenchant-2-2 \
    libgpgme11 \
    libgpgme-dev \
    enchant-2 \
    hunspell-en-us \
    libaspell15 \
    aspell \
    aspell-en \

    && rm -rf /var/lib/apt/lists/* \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Pre-install LLVM 9 toolchain for JIT/AOT CI (#237). Build from repo root:
#   docker build -f Docker/dev/ubuntu-22.04/Dockerfile -t php-compiler:22.04-dev .
COPY script/install-llvm9.sh /tmp/install-llvm9.sh
RUN chmod +x /tmp/install-llvm9.sh \
    && PHP_COMPILER_LLVM_INSTALL_DIR=/opt/llvm9 /tmp/install-llvm9.sh \
    && rm -f /tmp/install-llvm9.sh

ENV PHP_COMPILER_LLVM_PATH=/opt/llvm9
ENV LD_LIBRARY_PATH=/opt/llvm9
ENV PHP_COMPILER_CI_RAM_GB=8
ENV PHP_COMPILER_MEMORY_LIMIT=1536M
# Keep in sync with script/ci-defaults.env — image ENV wins over its ${:-default}
# and silently pinned step 4a at 4096M after the bump (#21104).
ENV PHP_COMPILER_LLVM_MEMORY_LIMIT=6144M

WORKDIR /compiler

CMD ["/bin/bash"]
