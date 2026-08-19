<?php
/**
 * #32554 — array ++/-- is Zend TypeError, not Analyzer compile abort.
 * php-src: Zend/zend_operators.c increment_function / decrement_function
 */
try {
    $a = [1];
    echo ++$a;
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    $h = ['k' => 1];
    echo ++$h;
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    $a = [1];
    $a++;
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    $a = [1];
    echo --$a;
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    $a = [1];
    $a--;
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
$n = 1;
echo ++$n, "\n";
