--TEST--
AOT: array ⊙ int arithmetic TypeError; array+array unions (#32346, zend_operators.c add_function)
--FILE--
<?php
try {
    var_dump([] + 1);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    var_dump(1 + []);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    var_dump([] * 2);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    [1] - [2];
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
$u = ['a' => 1] + ['b' => 2];
echo $u['a'], $u['b'], "\n";
--EXPECT--
Unsupported operand types: array + int
Unsupported operand types: int + array
Unsupported operand types: array * int
Unsupported operand types: array - array
12
--EXPECT_EXIT--
0
