--TEST--
stdlib range() float step includes endpoint (#15326, ext/standard/array.c)
--FILE--
<?php
$r = range(1.0, 2.0, 0.1);
echo count($r), "\n";
echo $r[count($r) - 1], "\n";
--EXPECT--
11
2
