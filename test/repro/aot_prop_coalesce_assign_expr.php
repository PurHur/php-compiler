<?php
/**
 * #35998 leftover of #33748 — `$o->prop ??= $x = n` must store n (ZEND_ASSIGN expr value).
 * php-src: Zend/zend_execute.c ZEND_COALESCE / ZEND_ASSIGN / zend_assign_to_variable
 *          Zend/zend_vm_def.h ZEND_ASSIGN_OBJ_OP
 */
class Same
{
    public $n = null;
}
$s = new Same();
$s->n ??= 5;
echo $s->n, '|';
$s->n = null;
$s->n ??= $x = 7;
echo $s->n, '|', $x, '|';
$s->n ??= $x = 9;
echo $s->n, '|', $x, '|';

class ParentN
{
    public $n = null;
}
class ChildN extends ParentN {}
$c = new ChildN();
$c->n ??= ($z = 3);
echo $c->n, '|', $z, '|';
$w = 4;
$c2 = new ChildN();
$c2->n ??= $w;
echo $c2->n, "\n";
