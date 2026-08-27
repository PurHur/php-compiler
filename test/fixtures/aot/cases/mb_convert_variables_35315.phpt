--TEST--
AOT mb_convert_variables() string by-ref (#35315 leftover #4572)
--FILE--
<?php
$latin1Cafe = "caf\xe9";
$a = $latin1Cafe;
$r = mb_convert_variables('UTF-8', 'ISO-8859-1', $a);
echo $r, "\n";
echo bin2hex($a), "\n";
$b = 'hello';
$r2 = mb_convert_variables('UTF-8', 'UTF-8', $b);
echo $r2, "\n";
echo $b, "\n";
--EXPECT--
ISO-8859-1
636166c3a9
UTF-8
hello
