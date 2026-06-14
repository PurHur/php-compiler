--TEST--
stdlib array_merge() — integer-key arrays reindex-append (ext/standard/array.c #4231)
--FILE--
<?php
$r = array_merge([0 => 'a'], [1 => 'b']);
var_export($r === ['a', 'b']);
echo "\n";
print_r($r);
echo "---\n";
print_r(array_merge([0 => 'a', 1 => 'b'], [2 => 'c']));
--EXPECT--
true
Array
(
    [0] => a
    [1] => b
)
---
Array
(
    [0] => a
    [1] => b
    [2] => c
)
