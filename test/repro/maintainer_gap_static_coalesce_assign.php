<?php
/**
 * Repro #32035 — ??= on uninitialized static property must store and persist.
 * php-src: Zend/zend_execute.c ZEND_ASSIGN_OP / ZEND_COALESCE on static properties.
 */
error_reporting(E_ALL);

class C
{
    public static $x;
}

C::$x ??= 7;
var_dump(C::$x);
C::$x ??= 9;
var_dump(C::$x);
