--TEST--
call argument spread rejects unknown named keys (Zend unpack parity, #4321 / #4669)
--FILE--
<?php
function sum(int $a, int $b, int $c): int {
    return $a + $b + $c;
}
$args = ['x' => 1, 'y' => 2, 'z' => 3];
try {
    sum(...$args);
    echo "no error\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Unknown named parameter $x
