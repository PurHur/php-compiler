<?php

/**
 * Repro #34207 — thin AOT var_dump(enum case) must match Zend (not abort).
 *
 * php-src: ext/standard/var.c — php_var_dump IS_OBJECT enum cases.
 */
enum E
{
    case A;
}

class C
{
    public const X = E::A;
}

var_dump(E::A);
var_dump(C::X);
