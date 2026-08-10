--TEST--
AOT: int|string param given array — Zend-shaped TypeError, not abort (#29859, zend_execute.c)
--FILE--
<?php
function f(int|string $x) {
    return $x;
}

try {
    f([]);
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
--EXPECTF--
TypeError: f(): Argument #1 ($x) must be of type string|int, array given, called in %s on line %d
