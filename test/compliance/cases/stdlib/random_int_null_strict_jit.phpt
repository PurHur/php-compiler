--TEST--
stdlib random_int(null) under strict_types JIT throws TypeError (#29779)
--JIT--
--FILE--
<?php
declare(strict_types=1);

try {
    echo random_int(null, 10), "\n";
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    echo random_int(0, null), "\n";
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
random_int(): Argument #1 ($min) must be of type int, null given
random_int(): Argument #2 ($max) must be of type int, null given
