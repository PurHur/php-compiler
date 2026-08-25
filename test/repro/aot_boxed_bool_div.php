<?php
/**
 * #34682 — untyped `$b = true` is a VALUE box; native⊙VALUE `/` must coerce bool→1.0.
 * php-src: Zend/zend_operators.c convert_scalar_to_number / div_function.
 */
$b = true;
var_dump($b / 2);
var_dump(5 / $b);
$f = false;
var_dump($f / 2);
