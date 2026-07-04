--TEST--
stdlib array_map() identity (null callback, nested inline array)
--FILE--
<?php
$nested = array_map(null, [[1], [2]]);
echo $nested[0][0], ',', $nested[1][0], "\n";
--EXPECT--
1,2
