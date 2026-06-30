--TEST--
stdlib array_fill() float count under strict call site JIT (#13860, ext/standard/array.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);
try {
    array_fill(1, 2.9, 'x');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: array_fill(): Argument #2 ($count) must be of type int, float given
