--TEST--
AOT: print_r(true)/print_r(false) match Zend (#24259)
--FILE--
<?php
echo "f=";
print_r(false);
echo "|t=";
print_r(true);
echo "|";
$a = [false, true];
echo "a0=";
print_r($a[0]);
echo "|a1=";
print_r($a[1]);
echo "|done\n";
--EXPECT--
f=|t=1|a0=|a1=1|done
