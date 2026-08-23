--TEST--
AOT: ReflectionFunction::$name + getName after construct (#33993, ext/reflection/php_reflection.c)
--FILE--
<?php
function hello_world() {}
$r = new ReflectionFunction('hello_world');
echo $r->name, '|', $r->getName(), "\n";
--EXPECT--
hello_world|hello_world
