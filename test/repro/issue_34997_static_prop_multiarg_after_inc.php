<?php
/**
 * #34997 — after two stmt-level A::inc(), multi-arg read of shared static via A and B
 * must not bind ARG_SEND to void StaticCall returns (Zend int(2) int(2)).
 */
class A
{
    public static $n = 0;

    public static function inc(): void
    {
        self::$n++;
    }
}
class B extends A
{
}
A::inc();
A::inc();
var_dump(A::$n, B::$n);
