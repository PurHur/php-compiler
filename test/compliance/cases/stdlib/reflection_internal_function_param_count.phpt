--TEST--
Stdlib: ReflectionFunction internal builtins report arginfo arity (#11453, ext/reflection/php_reflection.c)
--FILE--
<?php
echo (new ReflectionFunction('array_map'))->getNumberOfParameters(), "\n";
echo (new ReflectionFunction('strlen'))->getNumberOfParameters(), "\n";
echo (new ReflectionFunction('json_encode'))->getNumberOfParameters(), "\n";
--EXPECT--
3
1
3
