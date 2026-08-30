--TEST--
Language: assigned object vs native int == matches zend_compare (#35807 leftover of #35799)
--FILE--
<?php
error_reporting(E_ALL & ~E_NOTICE);
$o = new stdClass();
echo ($o == 1) ? "eq\n" : "neq\n";
echo ($o != 1) ? "ne\n" : "nne\n";
echo (1 == $o) ? "rev\n" : "nrev\n";
echo (new stdClass() == 1) ? "nat\n" : "nnat\n";
$a = [];
echo ($a == 1) ? "aeq\n" : "aneq\n";
?>
--EXPECT--
eq
nne
rev
nat
aneq
