<?php

declare(strict_types=1);

/**
 * Zend vs php-compiler: array_fill() strict call-site int for $start_index (#9906).
 *
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_fill) / Z_PARAM_LONG(start_key)
 */

try {
    array_fill('0', 2, 'x');
    echo "no throw\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

var_export(array_fill(0, 2, 'x'));
echo "\n";
