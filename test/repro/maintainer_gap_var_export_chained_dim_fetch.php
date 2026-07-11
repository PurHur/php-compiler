<?php

declare(strict_types=1);

/**
 * var_export($arr[i][0], true) must export the inner scalar, not NULL or the outer tuple (#15762).
 *
 * php-src: ext/standard/var.c — PHP_FUNCTION(var_export)
 * Compiler: lib/Compiler.php resolvePrecedingArrayDimFetchCallArgSlot chain tail for arg #0
 */

$b = [1 => [0 => 'a', 1 => 0]];
echo var_export($b[1][0], true), "\n";

preg_match('/(a)(b)/', 'ab', $m, PREG_OFFSET_CAPTURE);
echo var_export($m[1][0], true), "\n";
