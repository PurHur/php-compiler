--TEST--
stdlib ReflectionFunctionAbstract::getNamedArguments — not advertised on PHP 8.2 reference profile (#17658, ext/reflection/php_reflection.c)
--FILE--
<?php
echo method_exists(ReflectionFunction::class, 'getNamedArguments') ? "fail\n" : "ok\n";
--EXPECT--
ok
