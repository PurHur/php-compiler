--TEST--
AOT: unary +/- on array is TypeError array * int (#32553, zend_operators.c mul_function)
--FILE--
<?php
try {
    echo +[1];
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    echo -[1];
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
$a = [1];
try {
    echo +$a;
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    echo -$a;
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    var_dump(+[]);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Unsupported operand types: array * int
Unsupported operand types: array * int
Unsupported operand types: array * int
Unsupported operand types: array * int
Unsupported operand types: array * int
--EXPECT_EXIT--
0
