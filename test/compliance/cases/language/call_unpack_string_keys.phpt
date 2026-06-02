--TEST--
call argument spread rejects arrays with string keys (Zend VM parity, #4321)
--FILE--
<?php
function sum(int $a, int $b, int $c): int {
    return $a + $b + $c;
}
$args = ['x' => 1, 'y' => 2, 'z' => 3];
try {
    sum(...$args);
    echo "no error\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Cannot unpack array with string keys
