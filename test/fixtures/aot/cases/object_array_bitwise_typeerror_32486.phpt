--TEST--
AOT: object/array bitwise &|^~ / <<>> TypeError, not compile abort (#32486 leftover of #32477/#32346)
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
try {
    var_dump([1] & 1);
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
--EXPECT--
Unsupported operand types: stdClass & int
Unsupported operand types: stdClass | int
Unsupported operand types: stdClass ^ int
Unsupported operand types: stdClass << int
Unsupported operand types: stdClass >> int
Cannot perform bitwise not on stdClass
Unsupported operand types: array & int
Unsupported operand types: array << int
Cannot perform bitwise not on array
--EXPECT_EXIT--
0
