--TEST--
AOT: array_keys() nested array-returning producer — values/unique/merge (#21981)
--FILE--
<?php
// array_flip NestedJIT hits HashTable::iteratekeyed — separate hole; cover other producers.
echo implode(',', array_keys(array_values(['x' => 1, 'y' => 2]))), "\n";
echo implode(',', array_keys(array_unique(['a', 'a', 'b']))), "\n";
echo implode(',', array_keys(array_merge(['a' => 1], ['b' => 2]))), "\n";
--EXPECT--
0,1
0,2
a,b
