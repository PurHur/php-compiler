--TEST--
Language: native-object/array bitwise &|^~ and <<>> TypeError (#32486, Zend/zend_operators.c)
--FILE--
<?php
try {
    var_dump(new stdClass() & 1);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    var_dump(new stdClass() | 1);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    var_dump(new stdClass() ^ 1);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    var_dump(new stdClass() << 1);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    var_dump(new stdClass() >> 1);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    var_dump(~new stdClass());
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
class C32486 {}
try {
    var_dump(new C32486() & 2);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    var_dump([1] & 1);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    var_dump(1 | [1]);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    var_dump([1] << 1);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    var_dump(~[1]);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
Unsupported operand types: stdClass & int
Unsupported operand types: stdClass | int
Unsupported operand types: stdClass ^ int
Unsupported operand types: stdClass << int
Unsupported operand types: stdClass >> int
Cannot perform bitwise not on stdClass
Unsupported operand types: C32486 & int
Unsupported operand types: array & int
Unsupported operand types: int | array
Unsupported operand types: array << int
Cannot perform bitwise not on array
