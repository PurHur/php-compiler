--TEST--
AOT: ReflectionParameter::$name + getName after construct (#33993, ext/reflection/php_reflection.c)
--FILE--
<?php
function f($x, $y) {}
$r = new ReflectionParameter('f', 'x');
echo $r->name, '|', $r->getName(), "\n";
$r2 = new ReflectionParameter('f', 'y');
echo $r2->name, "\n";
--EXPECT--
x|x
y
