<?php

declare(strict_types=1);

/**
 * #23571 — array_reduce() inline Closure + null $initial must not TypeError the callback.
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_reduce) / php_array_reduce
 */
$viaVar = array_reduce([1, 2], static function ($c, $v) {
    return ($c ?? 0) + $v;
}, $init = null);
echo "viaVar=$viaVar\n";

echo 'literal=', array_reduce([1, 2], static function ($c, $v) {
    return ($c ?? 0) + $v;
}, null), "\n";

echo 'named=', array_reduce([1, 2], static fn($c, $v) => ($c ?? 0) + $v, initial: null), "\n";

echo 'arrow=', array_reduce([1, 2], static fn($c, $v) => ($c ?? 0) + $v, null), "\n";
