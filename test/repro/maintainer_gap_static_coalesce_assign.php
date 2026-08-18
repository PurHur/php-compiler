<?php
/**
 * #32035 — ??= on uninitialized static property must store; readback matches Zend.
 *
 * php-src: Zend/zend_execute.c ZEND_ASSIGN_OP / ZEND_COALESCE on static properties.
 *
 * @differential-repeat: 10 AOT static ??= store was a no-op (readback NULL)
 */
error_reporting(E_ALL);

class C32035
{
    public static $x;
}

C32035::$x ??= 7;
var_dump(C32035::$x);
C32035::$x ??= 9;
var_dump(C32035::$x);

class T32035
{
    public static int $n;
}

T32035::$n ??= 7;
var_dump(T32035::$n);
T32035::$n ??= 9;
var_dump(T32035::$n);

class U32035
{
    public static $y;
}

var_dump(U32035::$y ??= 3);
var_dump(U32035::$y);
