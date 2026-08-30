<?php
/**
 * #35807 leftover of #35799/#32503 — assigned (TYPE_VALUE) object vs native int == / !=.
 * php-src: Zend/zend_operators.c compare_function / zend_compare
 */
error_reporting(E_ALL & ~E_NOTICE);
$o = new stdClass();
echo ($o == 1) ? "eq\n" : "neq\n";
echo ($o != 1) ? "ne\n" : "nne\n";
echo (1 == $o) ? "rev\n" : "nrev\n";
echo (new stdClass() == 1) ? "nat\n" : "nnat\n";
$a = [];
echo ($a == 1) ? "aeq\n" : "aneq\n";
