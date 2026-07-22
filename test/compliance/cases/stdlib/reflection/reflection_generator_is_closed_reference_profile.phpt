--TEST--
ReflectionGenerator::isClosed() phantom on 8.2 reference profile (#22242, ext/reflection/php_reflection.c)
--FILE--
<?php
echo method_exists(ReflectionGenerator::class, 'isClosed') ? 'yes' : 'no', "\n";
--EXPECT--
no
