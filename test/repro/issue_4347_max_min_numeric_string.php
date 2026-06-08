<?php

declare(strict_types=1);

/**
 * Repro for #4347 — max()/min() numeric-string variadic coercion.
 *
 * @see ext/standard/array.c php_min_max (php-src)
 */

echo max(1, '2', 3.5), "\n";
echo min('3', 2), "\n";
