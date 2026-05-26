--TEST--
unset() with multiple targets in one statement (issue #2273)
--FILE--
<?php
$a = ['x' => 1, 'y' => 2];
unset($a['x'], $a['y']);
echo count($a), "\n";
--EXPECT--
0
