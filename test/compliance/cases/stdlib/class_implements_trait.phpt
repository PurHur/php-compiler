--TEST--
Stdlib: class_implements() on trait — empty array not false (VM, #5248)
--FILE--
<?php
trait T {}
$map = class_implements('T');
echo count($map), "\n";
echo class_implements('T') === [] ? '1' : '0';
echo "\n";
--EXPECT--
0
1
