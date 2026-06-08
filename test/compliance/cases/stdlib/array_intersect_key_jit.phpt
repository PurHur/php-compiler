--TEST--
stdlib array_intersect_key() JIT key-only intersect (#4188)
--JIT--
--FILE--
<?php
$a = ['a' => 1, 'b' => 2];
$b = ['a' => 9, 'c' => 3];
echo json_encode(array_intersect_key($a, $b)), "\n";
--EXPECT--
{"a":1}
