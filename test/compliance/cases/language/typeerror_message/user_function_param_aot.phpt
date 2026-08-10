--TEST--
AOT: user-function TypeError for non-coercible string to int param (#29745, zend_execute.c)
--FILE--
<?php
function f(int $x): int {
    return $x;
}

try {
    var_export(f("a"));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}

var_export(f("42"));
echo "\n";
--EXPECTF--
TypeError: f(): Argument #1 ($x) must be of type int, string given, called in %s on line %d
42
