<?php
/**
 * #35998 leftover of #33748 — `$o->p ??= $x = n` must store on null property and assign `$x`.
 * php-src: Zend/zend_execute.c ZEND_COALESCE / ZEND_ASSIGN / zend_assign_to_variable
 */
class C
{
    public $n = null;
}

$o = new C();
$o->n ??= $x = 7;
var_dump($o->n, $x);

$o2 = new C();
$o2->n ??= ($z = 7);
var_dump($o2->n, $z);

class D
{
    public $n = 5;
}

$d = new D();
$d->n ??= $y = 9;
var_dump($d->n, $y);
