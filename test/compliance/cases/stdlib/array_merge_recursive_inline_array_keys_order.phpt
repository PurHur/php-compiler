--TEST--
stdlib array_merge_recursive() inline array_keys() — string-key order preserved (#13761, ext/standard/array.c)
--FILE--
<?php
$merged = array_merge_recursive(['a' => 1], array_keys(['b' => 2]));
echo implode(',', array_keys($merged)), "\n";
echo $merged['a'], "\n";
echo $merged[0], "\n";
--EXPECT--
a,0
1
b
