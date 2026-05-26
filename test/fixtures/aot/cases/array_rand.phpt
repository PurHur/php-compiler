--TEST--
AOT array_rand() on packed lists (#2321)
--FILE--
<?php
$a = array();
$a[] = 10;
$a[] = 20;
$a[] = 30;
$k = array_rand($a);
echo (is_int($k) && isset($a[$k])) ? 'one' : 'bad', "\n";
$keys = array_rand($a, 2);
echo (is_array($keys) && 2 === count($keys)) ? 'two' : 'bad', "\n";
--EXPECT--
one
two
