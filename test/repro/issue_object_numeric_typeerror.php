<?php
/**
 * #32477 — native-object unary +/- and object⊙int must TypeError, not abort compile.
 * php-src: Zend/zend_operators.c ZEND_TRY_UNARY_OBJECT_OPERATION / add_function
 */
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
