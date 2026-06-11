--TEST--
Stdlib: ReflectionClass::isEnum() — enum vs class (php_reflection.c, #5666)
--FILE--
<?php
enum E: int { case A = 1; }
class C {}
echo (new ReflectionClass('E'))->isEnum() ? '1' : '0';
echo (new ReflectionClass('C'))->isEnum() ? '1' : '0';
echo (new ReflectionClass('stdClass'))->isEnum() ? '1' : '0';
echo "\n";
--EXPECT--
100
