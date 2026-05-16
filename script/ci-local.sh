#!/usr/bin/env bash
# Local CI baseline: install deps and run the full PHPUnit suite (no Docker).
set -euo pipefail
cd "$(dirname "$0")/.."
composer install --no-interaction --ignore-platform-reqs --no-plugins
php vendor/bin/phpunit "$@"
