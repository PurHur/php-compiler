--TEST--
AOT array_flip() duplicate values — last key wins
--FILE--
<?php
$f = array_flip([1, 1]);
echo $f[1], "\n";
$g = array_flip(['a' => 'x', 'b' => 'x']);
echo $g['x'], "\n";
--EXPECT--
1
b
