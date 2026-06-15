--TEST--
AOT: preg_match() $matches flags offset (issue #4417)
--FILE--
<?php
preg_match('/(\d+)/', 'abc123', $m, 256, 3);
echo $m[0][0], ':', $m[0][1], "\n";
echo preg_match('/x/', 'abc'), "\n";
--EXPECT--
123:3
0
