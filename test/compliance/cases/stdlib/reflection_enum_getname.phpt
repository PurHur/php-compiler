--TEST--
stdlib ReflectionEnum::__construct() + getName() backed/unit enums (#4612, php_reflection.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }
enum U { case C; }

$r = new ReflectionEnum(E::class);
echo $r->getName(), "\n";
echo $r->isBacked() ? "backed\n" : "unit\n";
echo count($r->getCases()), "\n";

$ru = new ReflectionEnum(U::class);
echo $ru->getName(), "\n";
echo $ru->isBacked() ? "backed\n" : "unit\n";

class NotAnEnum {}
try {
    new ReflectionEnum(NotAnEnum::class);
    echo "no throw\n";
} catch (ReflectionException $e) {
    echo $e->getMessage(), "\n";
}
try {
    new ReflectionEnum(E::class, 'extra');
    echo "no argc\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
E
backed
2
U
unit
Class "NotAnEnum" is not an enum
ReflectionEnum::__construct() expects exactly 1 argument, 2 given
