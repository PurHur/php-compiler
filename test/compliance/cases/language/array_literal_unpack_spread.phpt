--TEST--
language: array literal unpack/spread — reindex int keys, overwrite string keys (Zend zend_compile.c)
--FILE--
<?php
$a = [1, 2];
$b = [0 => 9, 3 => 4];
$r1 = [...$a, 3, ...$b];
echo count($r1), "\n";
echo $r1[0], ',', $r1[1], ',', $r1[2], ',', $r1[3], ',', $r1[4], "\n";

$c = ['x' => 1];
$d = ['x' => 2, 'y' => 3];
$r2 = ['x' => 0, ...$c, ...$d];
echo $r2['x'], ',', $r2['y'], "\n";
?>
--EXPECT--
5
1,2,3,9,4
2,3

