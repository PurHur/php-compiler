<?php
/**
 * #32444 — Zend convert_scalar_to_number IS_ARRAY: empty → 0, non-empty → 1.
 * php-src: Zend/zend_operators.c _zval_get_long_func
 */
var_dump((int) []);
var_dump((int) [1, 2]);
var_dump((float) []);
var_dump((float) [7]);
$empty = [];
$full = [9];
var_dump((int) $empty);
var_dump((int) $full);
