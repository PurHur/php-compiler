<?php
/**
 * #32328 — AOT var_dump(float) must use PG(serialize_precision) / %.*H,
 * not echo's PG(precision) zend_gcvt.
 *
 * php-src: ext/standard/var.c php_var_dump IS_DOUBLE
 *          Zend/zend_strtod.c zend_gcvt
 */
echo 1 / 3, "\n";
var_dump(0.1);
var_dump(1 / 3);
var_dump(0.1 + 0.2);
var_dump(PHP_INT_MAX + 1);
var_dump(INF);
var_dump(NAN);
