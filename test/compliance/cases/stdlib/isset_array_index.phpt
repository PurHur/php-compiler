--TEST--
stdlib isset() on array integer offset
--FILE--
<?php
$a = array(10, 20);
echo isset($a[0]) ? 'y' : 'n', "\n";
echo isset($a[1]) ? 'y' : 'n', "\n";
echo isset($a[2]) ? 'y' : 'n', "\n";
$r = range(1, 2);
echo isset($r[0]) ? 'y' : 'n', "\n";
echo isset($r[2]) ? 'y' : 'n', "\n";
--EXPECT--
y
y
n
y
n
