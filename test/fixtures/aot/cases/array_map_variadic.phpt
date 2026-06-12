--TEST--
AOT: array_map() null zip across multiple arrays (#4539)
--FILE--
<?php
$z = array_map(null, [1, 2], ['a', 'b']);
echo $z[0][0], $z[0][1], '|', $z[1][0], $z[1][1], "\n";
$z3 = array_map(null, [1, 2], ['a', 'b'], [1, 0]);
echo $z3[0][2], '|', $z3[1][2], "\n";
--EXPECT--
1a|2b
1|0
