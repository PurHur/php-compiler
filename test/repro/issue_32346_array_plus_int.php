<?php
/**
 * #32346 — array ⊙ int/array arithmetic must TypeError (except array+array union).
 * php-src: Zend/zend_operators.c add_function / sub_function / mul_function.
 */
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
