<?php
/**
 * #32452 — Zend (int)/(float) on IS_OBJECT: E_WARNING + 1 / 1.0.
 * php-src: Zend/zend_operators.c _zval_get_long_func / _zval_get_double_func
 * AOT previously aborted: (int) cast unsupported operand type in JIT (TYPE_OBJECT).
 */
$o = new stdClass();
echo (int) $o;
echo "\n";
echo (float) $o;
echo "\n";
echo (int) (new stdClass());
echo "\n";
