--TEST--
SplFixedArray serialize roundtrip (#13179)
--FILE--
<?php
$a = SplFixedArray::fromArray([10, 20, 30]);
echo method_exists($a, '__serialize') ? '1' : '0', "\n";
$b = unserialize(serialize($a));
echo $b->getSize(), "\n";
echo $b[0], ',', $b[1], ',', $b[2], "\n";
--EXPECT--
1
3
10,20,30
