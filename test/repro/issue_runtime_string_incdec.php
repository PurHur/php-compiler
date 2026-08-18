<?php
/**
 * #32435 — runtime string ++ must use zend increment_string(), not 0+1.
 * Compile-time 'a'++ folds; function-return / boxed strings previously SIGSEGV or printed 1.
 */
function f() { return 'a'; }
$s = f();
$s++;
echo $s, "\n";
function g() { return '9'; }
$n = g();
$n++;
var_dump($n);
function h() { return 'z'; }
$z = h();
$z++;
echo $z, "\n";
