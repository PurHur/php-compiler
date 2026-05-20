--TEST--
language: array append assignment ($arr[] = value)
--FILE--
<?php
$a = array();
$a[] = 'first';
$a[] = 'second';
echo count($a), "\n";
echo $a[0], $a[1], "\n";
--EXPECT--
2
firstsecond
