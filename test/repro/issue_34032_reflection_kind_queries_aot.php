<?php
/**
 * #34032 — ReflectionClass kind queries under thin AOT.
 *
 * Expect (Zend):
 *   I=1,0,0,0
 *   A=0,1,0,0
 *   T=0,0,1,0
 *   E=0,0,0,1
 *   C=0,0,0,0
 * Columns: isInterface,isAbstract,isTrait,isEnum
 */
interface I
{
}

abstract class A
{
    abstract public function m();
}

trait T
{
}

enum E
{
    case X;
}

class C
{
}

foreach (['I', 'A', 'T', 'E', 'C'] as $n) {
    $r = new ReflectionClass($n);
    echo $n, '=',
        ($r->isInterface() ? '1' : '0'), ',',
        ($r->isAbstract() ? '1' : '0'), ',',
        ($r->isTrait() ? '1' : '0'), ',',
        ($r->isEnum() ? '1' : '0'),
        "\n";
}
