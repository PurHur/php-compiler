<?php
/**
 * #35987 leftover of #35898 / #33748 — `$r =& $obj->prop; $r ??= n` must write through.
 * php-src: Zend/zend_execute.c ZEND_COALESCE / zend_assign_to_variable_reference
 *          Zend/zend_object_handlers.c zend_std_get_property_ptr_ptr
 */
class Same
{
    public $n = null;
}
$s = new Same();
$s->n ??= 5;
echo $s->n, '|';
$s->n = null;
$r =& $s->n;
$r ??= 7;
echo $r, '|', $s->n, '|';
$r ??= 9;
echo $r, '|', $s->n, '|';

class ParentN
{
    public $n = null;
}
class ChildN extends ParentN {}
$c = new ChildN();
$q =& $c->n;
$q ??= 3;
echo $q, '|', $c->n, '|';
$x = null;
$x ??= 1;
echo $x, "\n";
