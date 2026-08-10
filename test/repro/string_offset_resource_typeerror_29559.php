<?php

/**
 * Issue #29559 — string offset TypeError uses zend_zval_type_name "resource"
 * (not ClassEntry "Resource").
 *
 * php-src: Zend/zend_execute.c zend_check_string_offset; Zend/zend_types.h zend_zval_type_name
 */
error_reporting(E_ALL);
$s = 'ab';
$r = fopen('php://memory', 'r');
try {
    echo $s[$r];
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
try {
    $s[$r] = 'z';
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
fclose($r);
