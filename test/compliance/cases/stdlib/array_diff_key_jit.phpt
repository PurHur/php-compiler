--TEST--
stdlib array_diff_key() JIT key-only diff (#4188)
--JIT--
--FILE--
<?php
$a = ['a' => 1, 'b' => 2];
$b = ['a' => 9, 'c' => 3];
echo json_encode(array_diff_key($a, $b)), "\n";
--EXPECT--
{"b":2}
