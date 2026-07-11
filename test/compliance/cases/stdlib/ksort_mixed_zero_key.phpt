--TEST--
stdlib ksort()/krsort() on int 0 + string '0' colliding keys (ext/standard/array.c)
--FILE--
<?php
$a = [0 => 'a', '0' => 'b'];
ksort($a);
echo json_encode($a), "\n";
$b = [0 => 'a', '0' => 'b'];
krsort($b);
echo json_encode($b), "\n";
--EXPECT--
["b"]
["b"]
