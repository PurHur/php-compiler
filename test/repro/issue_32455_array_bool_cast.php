<?php
/**
 * #32455 — Zend convert_to_boolean IS_ARRAY: empty → false, non-empty → true.
 * php-src: Zend/zend_operators.c convert_to_boolean
 */
var_dump((bool) []);
var_dump((bool) [1]);
$empty = [];
$full = [9];
var_dump((bool) $empty);
var_dump((bool) $full);
