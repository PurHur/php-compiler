FROM ubuntu:22.04

ENV DEBIAN_FRONTEND=noninteractive
ENV PHP_COMPILER_PHP=php
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PHP_CS_FIXER_IGNORE_ENV=true

RUN apt-get update && apt-get install -y --no-install-recommends \
    software-properties-common gnupg \
    && add-apt-repository -y ppa:ondrej/php \
    && apt-get update && apt-get install -y --no-install-recommends \
    git curl unzip ca-certificates dpkg \
    php8.2-cli php8.2-mbstring php8.2-xml php8.2-ffi php8.2-posix php8.2-phar \
    php8.2-tokenizer php8.2-dom php8.2-xmlwriter \
    build-essential clang llvm-14-dev libjansson4 \
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
ENV PHP_COMPILER_LLVM_MEMORY_LIMIT=4096M

WORKDIR /compiler

CMD ["/bin/bash"]
