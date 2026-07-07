<?php

declare(strict_types=1);

/**
 * Maintainer repro: forward-profile builtins must not leak on 8.2 reference profile (#17206).
 *
 * Default harness (unset PHP_COMPILER_PROFILE) matches Zend 8.2 function_exists gates.
 */

$leaked = [];
foreach ([
    'mb_trim',
    'crc32c',
    'hebrevc',
    'attribute_exists',
    'class_uses_recursive',
] as $fn) {
    if (function_exists($fn)) {
        $leaked[] = $fn;
    }
}

if ([] !== $leaked) {
    fwrite(STDERR, 'fail: leaked on reference profile: '.implode(', ', $leaked)."\n");
    exit(1);
}

echo "ok\n";
