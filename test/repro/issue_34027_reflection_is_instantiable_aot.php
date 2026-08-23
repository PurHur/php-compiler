<?php
/**
 * #34027 — ReflectionClass::isInstantiable under thin AOT.
 *
 * Expect (Zend):
 *   U=1
 *   A=0
 *   I=0
 *   T=0
 *   E=0
 *   P=0
 */
class U
{
}

abstract class A
{
    abstract public function m();
}

interface I
{
}

trait T
{
}

enum E
{
    case X;
}

class P
{
    private function __construct()
    {
    }
}

echo 'U=', (new ReflectionClass('U'))->isInstantiable() ? '1' : '0', "\n";
echo 'A=', (new ReflectionClass('A'))->isInstantiable() ? '1' : '0', "\n";
echo 'I=', (new ReflectionClass('I'))->isInstantiable() ? '1' : '0', "\n";
echo 'T=', (new ReflectionClass('T'))->isInstantiable() ? '1' : '0', "\n";
echo 'E=', (new ReflectionClass('E'))->isInstantiable() ? '1' : '0', "\n";
echo 'P=', (new ReflectionClass('P'))->isInstantiable() ? '1' : '0', "\n";
