--TEST--
ReflectionFunction::createFromCallable()/createFromFunction() phantom on 8.2 reference profile (#16724, ext/reflection/php_reflection.c)
--FILE--
<?php
echo 'createFromCallable=', var_export(method_exists('ReflectionFunction', 'createFromCallable'), true), "\n";
echo 'createFromFunction=', var_export(method_exists('ReflectionFunction', 'createFromFunction'), true), "\n";
echo 'createFromClosure=', var_export(method_exists('ReflectionMethod', 'createFromClosure'), true), "\n";
echo 'createFromMethodName=', var_export(method_exists('ReflectionMethod', 'createFromMethodName'), true), "\n";
--EXPECT--
createFromCallable=false
createFromFunction=false
createFromClosure=false
createFromMethodName=false
