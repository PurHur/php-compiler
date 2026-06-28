<?php

declare(strict_types=1);

/**
 * Repro #12947 — get_defined_constants(category:) withheld on reference profile (8.4.0-dev).
 *
 * php-src: ext/standard/basic_functions.c — PHP 8.4+ $category named parameter.
 */

try {
    get_defined_constants(category: 'Core');
    echo "fail: category accepted on reference profile\n";
    exit(1);
} catch (\Error $e) {
    echo 'category-param:', $e->getMessage(), "\n";
}

echo "ok\n";
