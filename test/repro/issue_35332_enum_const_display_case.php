<?php
/**
 * Repro #35332 — file-scope const of enum case must not lowercase get_class/var_dump.
 * php-src: Zend/zend_enum.c, ext/standard/var.c (peer #34783)
 */
enum E: int { case A = 1; }
const X = E::A;
var_dump(X);
var_dump(get_class(X));
var_dump(E::A);
