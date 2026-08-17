<?php
/**
 * #31964 — AOT integer overflow must promote to float (zend_operators.c add/mul).
 */
var_dump(PHP_INT_MAX + 1);
var_dump(PHP_INT_MAX * 2);
