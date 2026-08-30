--TEST--
AOT: assigned array/object vs native int ordered compare is zend_compare (#35799 leftover of #32503)
--FILE--
<?php
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
--EXPECT--
1
agt
-1
1
0
nogt
0
--EXPECT_EXIT--
0
