--TEST--
array literal spread appends list elements (VM+JIT, #1361 / #141)
--FILE--
<?php
$a = [1, 2];
$b = [...$a, 3];
echo count($b), ':', $b[0], ',', $b[1], ',', $b[2], "\n";
$c = ['x' => 1, 'y' => 2];
$d = [...$c, 'z' => 3];
echo count($d), ':', $d['x'], ',', $d['y'], ',', $d['z'], "\n";
--EXPECT--
3:1,2,3
3:1,2,3
