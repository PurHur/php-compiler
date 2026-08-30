<?php
// #35807: assigned TYPE_VALUE object vs native int == uses zend_compare (not 0==n).
error_reporting(E_ALL & ~E_NOTICE);
$o = new stdClass();
echo ($o == 1) ? "eq\n" : "neq\n";
echo ($o != 1) ? "ne\n" : "nne\n";
echo (1 == $o) ? "rev\n" : "nrev\n";
echo (new stdClass() == 1) ? "nat\n" : "nnat\n";
$a = [];
echo ($a == 1) ? "aeq\n" : "aneq\n";
