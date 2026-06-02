--TEST--
stdlib compact() array argument expands variable names (issue #3468)
--FILE--
<?php
$foo = 1;
$bar = 2;
$keys = ['foo', 'bar'];
$c = compact($keys);
echo $c['foo'], "\n";
echo $c['bar'], "\n";
$nested = compact(['foo'], ['bar']);
echo $nested['foo'], "\n";
echo $nested['bar'], "\n";
--EXPECT--
1
2
1
2
