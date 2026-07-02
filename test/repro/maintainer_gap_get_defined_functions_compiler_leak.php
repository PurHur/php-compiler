<?php

declare(strict_types=1);

/**
 * Maintainer repro: get_defined_functions() must not leak __compiler_* helpers (#15046).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(get_defined_functions)
 */

$internal = get_defined_functions()['internal'];

if ('zend_version' !== $internal[0]) {
    echo 'FAIL internal[0]=' . $internal[0];
    exit(1);
}

foreach ($internal as $name) {
    if (str_starts_with($name, '__compiler_')) {
        echo 'FAIL leaked ' . $name;
        exit(1);
    }
}

if (function_exists('__compiler_is_superglobal_name')) {
    echo 'FAIL function_exists(__compiler_is_superglobal_name)';
    exit(1);
}

echo "ok\n";
