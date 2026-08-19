--TEST--
AOT: unary +/- and object⊙int TypeError, not compile abort (#32477 leftover of #32452)
--FILE--
<?php
try {
    var_dump(+new stdClass());
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    var_dump(-new stdClass());
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
$o = new stdClass();
try {
    var_dump(+$o);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    var_dump($o + 1);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    var_dump(1 + $o);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
class C32477 {}
try {
    var_dump(new C32477() * 2);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Unsupported operand types: stdClass
Unsupported operand types: stdClass
Unsupported operand types: stdClass
Unsupported operand types: stdClass + int
Unsupported operand types: int + stdClass
Unsupported operand types: C32477 * int
--EXPECT_EXIT--
0
