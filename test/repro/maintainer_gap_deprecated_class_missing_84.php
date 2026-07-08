<?php

declare(strict_types=1);

/**
 * Maintainer repro: Deprecated builtin attribute class on PHP_COMPILER_PROFILE=8.4 (#17318).
 */

if (!class_exists('Deprecated', false)) {
    echo "fail: Deprecated missing on 8.4 forward profile\n";
    exit(1);
}

echo "ok\n";
