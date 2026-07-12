--TEST--
stdlib ReflectionMethod::getTentativeReturnType() on internal DateTime::format (#18226, ext/reflection/php_reflection.c)
--FILE--
<?php
$m = new ReflectionMethod('DateTime', 'format');
var_export(method_exists($m, 'getTentativeReturnType'));
echo "\n";
var_export($m->hasTentativeReturnType());
echo "\n";
var_export($m->getTentativeReturnType()?->getName());
echo "\n";
var_export($m->getReturnType());
echo "\n";
--EXPECT--
true
true
'string'
NULL
