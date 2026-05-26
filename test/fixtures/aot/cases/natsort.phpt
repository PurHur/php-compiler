--TEST--
AOT: natsort() natural order strings
--FILE--
<?php
$a = array();
$a[] = 'b2';
$a[] = 'b10';
$a[] = 'b1';
natsort($a);
echo implode('|', $a), "\n";
--EXPECT--
b1|b2|b10
