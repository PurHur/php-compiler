--TEST--
stdlib: current()/key() inline array literal by-ref accepted (#10654, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

echo 'current_empty=', var_export(current([]), true), "\n";
echo 'key_empty=', var_export(key([]), true), "\n";
echo 'current_lit=', var_export(current([1, 2]), true), "\n";
echo 'key_lit=', var_export(key([1, 2]), true), "\n";

try {
    reset([1, 2]);
    echo "reset: no throw\n";
} catch (Throwable $e) {
    echo 'reset: ', get_class($e), ': ', $e->getMessage(), "\n";
}

$a = [1, 2];
echo 'var_current=', var_export(current($a), true), ' key=', var_export(key($a), true), "\n";
--EXPECT--
current_empty=false
key_empty=NULL
current_lit=1
key_lit=0
reset: Error: reset(): Argument #1 ($array) cannot be passed by reference
var_current=1 key=0
--CREDITS--
PurHur/php-compiler issue #10654
