--TEST--
ReflectionParameter::isNamed()/getValue() phantom vs Zend under PROFILE=8.4 (#25057)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
function f(int $a = 1) {}
$p = (new ReflectionFunction('f'))->getParameters()[0];
echo method_exists($p, 'isNamed') ? "isNamed=1\n" : "isNamed=0\n";
echo method_exists($p, 'getValue') ? "getValue=1\n" : "getValue=0\n";
--EXPECT--
isNamed=0
getValue=0
