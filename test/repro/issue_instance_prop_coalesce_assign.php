<?php
/**
 * #33748 — instance ??= on uninitialized declared property must store (Zend ZEND_ASSIGN_OBJ_OP).
 *
 * php-src: Zend/zend_vm_def.h ZEND_COALESCE / ZEND_ASSIGN_OBJ_OP
 * php-src: Zend/zend_execute.c object assign
 *
 * @differential-repeat: 10 AOT instance ??= store was a no-op (Undefined property)
 */
error_reporting(E_ALL);

class C33748
{
    public $p;
}

$o = new C33748();
$o->p ??= 5;
echo $o->p, "\n";
$o->p ??= 9;
echo $o->p, "\n";

class N33748
{
    public $p = null;
}

$n = new N33748();
$n->p ??= 7;
echo $n->p, "\n";

class S33748
{
    public $p = 3;
}

$s = new S33748();
$s->p ??= 8;
echo $s->p, "\n";
