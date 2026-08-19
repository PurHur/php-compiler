<?php
/**
 * #32553 leftover of #32346/#32477 — unary +/- on array is mul_function (array * int).
 * php-src: Zend/zend_operators.c mul_function
 * AOT previously aborted: Not implemented escape operand UnaryPlus/UnaryMinus.
 */
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
