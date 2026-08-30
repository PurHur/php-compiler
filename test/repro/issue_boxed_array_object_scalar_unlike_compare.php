<?php
/**
 * #35799 leftover of #32503/#32528 — assigned (TYPE_VALUE) array/object vs native int.
 * php-src: Zend/zend_operators.c compare_function / zend_compare
 */
error_reporting(E_ALL & ~E_NOTICE);
$a = [];
echo $a <=> 1, "\n";
echo ($a > 1) ? "agt\n" : "nagt\n";
echo 1 <=> $a, "\n";
$a2 = [1];
echo $a2 <=> 0, "\n";
$o = new stdClass();
echo $o <=> 1, "\n";
echo ($o > 1) ? "ogt\n" : "nogt\n";
echo 1 <=> $o, "\n";
