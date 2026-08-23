<?php
/**
 * #34043 — ReflectionClass::isFinal under thin AOT.
 *
 * Expect (Zend):
 *   U=0
 *   F=1
 *   E=1
 *   A=0
 *   I=0
 *   T=0
 *   C=1
 *   G=1
 */
class U
{
}

final class F
{
}

enum E
{
    case X;
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

echo 'U=', (new ReflectionClass(U::class))->isFinal() ? '1' : '0', "\n";
echo 'F=', (new ReflectionClass(F::class))->isFinal() ? '1' : '0', "\n";
echo 'E=', (new ReflectionClass(E::class))->isFinal() ? '1' : '0', "\n";
echo 'A=', (new ReflectionClass(A::class))->isFinal() ? '1' : '0', "\n";
echo 'I=', (new ReflectionClass(I::class))->isFinal() ? '1' : '0', "\n";
echo 'T=', (new ReflectionClass(T::class))->isFinal() ? '1' : '0', "\n";
echo 'C=', (new ReflectionClass(Closure::class))->isFinal() ? '1' : '0', "\n";
echo 'G=', (new ReflectionClass(Generator::class))->isFinal() ? '1' : '0', "\n";
