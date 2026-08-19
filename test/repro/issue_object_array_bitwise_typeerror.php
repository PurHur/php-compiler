<?php
/**
 * #32486 — native-object/array bitwise &|^~ and <<>> must TypeError, not abort compile.
 * php-src: Zend/zend_operators.c bitwise_and/or/xor_function, shift_left/right_function,
 * bitwise_not_function (leftover of #32477 / #32346).
 */
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
