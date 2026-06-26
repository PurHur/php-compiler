--TEST--
ReflectionFunction::getReturnType() on never — getName() is never (#9655, ext/reflection/php_reflection.c)
--FILE--
<?php
function f(): never
{
    throw new Exception('x');
}
$r = new ReflectionFunction('f');
echo $r->getReturnType()->getName(), "\n";
--EXPECT--
never
