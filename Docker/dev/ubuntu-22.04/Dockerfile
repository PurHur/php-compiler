FROM ubuntu:22.04

ENV DEBIAN_FRONTEND=noninteractive
ENV PHP_COMPILER_PHP=php
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PHP_CS_FIXER_IGNORE_ENV=true

RUN apt-get update && apt-get install -y --no-install-recommends \
    software-properties-common gnupg \
    && add-apt-repository -y ppa:ondrej/php \
    && apt-get update && apt-get install -y --no-install-recommends \
    git curl unzip ca-certificates \
    php8.2-cli php8.2-mbstring php8.2-xml php8.2-ffi php8.2-posix php8.2-phar \
    php8.2-tokenizer php8.2-dom php8.2-xmlwriter \
    build-essential clang llvm-14-dev libjansson4 \
    && rm -rf /var/lib/apt/lists/* \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /compiler

CMD ["/bin/bash"]
