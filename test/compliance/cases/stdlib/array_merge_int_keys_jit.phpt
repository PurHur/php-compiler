--TEST--
stdlib array_merge() — integer-key arrays reindex-append JIT (#4231)
--JIT--
--FILE--
<?php
$r = array_merge([0 => 'a'], [1 => 'b']);
var_export($r === ['a', 'b']);
echo "\n";
print_r($r);
--EXPECT--
true
Array
(
    [0] => a
    [1] => b
)
