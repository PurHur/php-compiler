--TEST--
stdlib str_replace() array $search/$replace (#11056)
--FILE--
<?php
echo str_replace(['a', 'b'], ['A', 'B'], 'ab'), "\n";
$r = str_replace('a', 'A', ['a' => 'x', 'b' => 'y']);
echo $r['a'], "\n";
--EXPECT--
AB
x
