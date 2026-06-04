--TEST--
stdlib array_slice() preserve_keys=true on associative arrays (#4227)
--FILE--
<?php
$a = ['a' => 1, 'b' => 2, 'c' => 3];
$s = array_slice($a, 1, 2, true);
echo count($s), "\n";
echo $s['b'], "\n";
echo $s['c'], "\n";
--EXPECT--
2
2
3
