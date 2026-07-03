--TEST--
ReflectionProperty::isDynamic() phantom on 8.2 reference profile (#15676, ext/reflection/php_reflection.c)
--FILE--
<?php
$o = new stdClass();
$o->x = 42;
$p = new ReflectionProperty($o, 'x');
echo 'isDynamic=', method_exists($p, 'isDynamic') ? 'yes' : 'no', "\n";
--EXPECT--
isDynamic=no
