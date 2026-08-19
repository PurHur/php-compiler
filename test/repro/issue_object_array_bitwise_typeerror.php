<?php
/**
 * #32486 — native-object/array bitwise &|^~ and <<>> must TypeError, not abort compile.
 * php-src: Zend/zend_operators.c bitwise_*_function / shift_*_function / bitwise_not_function
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
