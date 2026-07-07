--TEST--
ReflectionEnum::fromName() phantom on 8.2 reference profile (#17103, ext/reflection/php_reflection.c)
--FILE--
<?php
enum E { case A; case B; }
echo method_exists(ReflectionEnum::class, 'fromName') ? 'yes' : 'no', "\n";
--EXPECT--
no
