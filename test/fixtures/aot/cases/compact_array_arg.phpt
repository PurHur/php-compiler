--TEST--
AOT: compact() array argument (issue #3468)
--FILE--
<?php
$foo = 10;
$bar = 20;
$keys = ['foo', 'bar'];
$c = compact($keys);
echo $c['foo'], "\n";
echo $c['bar'], "\n";
--EXPECT--
10
20
