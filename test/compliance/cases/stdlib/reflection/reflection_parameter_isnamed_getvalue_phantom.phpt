--TEST--
ReflectionParameter::isNamed()/getValue() phantom vs Zend (#25057, ext/reflection/php_reflection.c)
--FILE--
<?php
function f(int $a = 1) {}
$p = (new ReflectionFunction('f'))->getParameters()[0];
echo method_exists($p, 'isNamed') ? "isNamed=1\n" : "isNamed=0\n";
echo method_exists($p, 'getValue') ? "getValue=1\n" : "getValue=0\n";
echo method_exists($p, 'getName') ? "getName=1\n" : "getName=0\n";
--EXPECT--
isNamed=0
getValue=0
getName=1
