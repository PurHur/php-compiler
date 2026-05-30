--TEST--
language eval() return value for trailing expression (VM, issue #3358)
--FILE--
<?php
$x = 10;
$r = eval('$x + 1');
echo $r, "\n";
$r2 = eval('$x = 5;');
echo $r2 === null ? "null\n" : "not-null\n";
echo $x, "\n";
--EXPECT--
11
null
5
