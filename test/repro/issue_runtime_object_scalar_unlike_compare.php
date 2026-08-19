<?php
/**
 * #32540 — assigned object vs string/int == / <=> (not literals).
 * php-src: Zend/zend_operators.c compare_function / zend_compare
 */
$o = new stdClass();
$s = 'a';
echo ($o === $s) ? "ident\n" : "nident\n";
echo ($o == $s) ? "eq\n" : "neq\n";
echo ($o <=> $s), "\n";
echo ("a" <=> $o), "\n";
$i = 1;
echo ($o <=> $i), "\n";
echo ($o > $i) ? "igt\n" : "nigt\n";
