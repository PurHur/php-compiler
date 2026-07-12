<?php

declare(strict_types=1);

/**
 * Issue #5683 — json_encode() on unit enum case must throw ValueError on PHP 8.3+ (ext/json/php_json.c).
 *
 * Run with forward profile: export PHP_COMPILER_PROFILE=8.4 before bin/vm.php.
 */
enum E
{
    case A;
}

try {
    json_encode(E::A);
    echo "no_exception\n";
    exit(1);
} catch (\ValueError $e) {
    echo $e->getMessage(), "\n";
}
