--TEST--
stdlib array_diff() — TypeError when variadic operand is bool (php-src ext/standard/array.c; #10783)
--FILE--
<?php
$a = array(1, 2, 3);
$b = array(2, 4);
try {
    array_diff($a, $b, true);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: array_diff(): Argument #3 must be of type array, true given
