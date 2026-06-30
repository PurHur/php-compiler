--TEST--
stdlib array_pad() float length under strict call site (#13876, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);
try {
    array_pad([1, 2], 2.9, 0);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: array_pad(): Argument #2 ($length) must be of type int, float given
