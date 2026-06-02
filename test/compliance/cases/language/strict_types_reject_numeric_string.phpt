--TEST--
declare(strict_types=1) rejects numeric string for int parameter (issues #156, #4482)
--FILE--
<?php
declare(strict_types=1);

function f(int $x): int {
    return $x;
}

try {
    var_dump(f("1"));
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECTF--
TypeError
f(): Argument #1 ($x) must be of type int, string given, called in %s on line %d
