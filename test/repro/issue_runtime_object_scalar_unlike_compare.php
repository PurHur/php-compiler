<?php
/**
 * #32540 leftover of #32515 — assigned object vs string/int == / <=> must match Zend.
 * php-src: Zend/zend_operators.c compare_function / zend_compare
 */
$o = new stdClass();
$s = 'a';
echo ($o === $s) ? "ident\n" : "nident\n";
echo ($o == $s) ? "eq\n" : "neq\n";
echo $o <=> $s, "\n";
echo $s <=> $o, "\n";
echo (new stdClass() <=> "a"), "\n";

$i = 1;
echo $o <=> $i, "\n";
echo ($o > $i) ? "gt\n" : "ngt\n";
