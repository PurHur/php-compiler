--TEST--
AOT: (array) stdClass / ArrayObject after #33869 kind mask (#33863 follow-up)
--FILE--
<?php
$o = new stdClass();
$o->x = 9;
echo ((array)$o)['x'], "\n";
$ao = new ArrayObject([3, 4]);
echo implode(',', (array)$ao), "\n";
--EXPECT--
9
3,4
