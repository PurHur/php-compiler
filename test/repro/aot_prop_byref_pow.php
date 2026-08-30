<?php
/**
 * #35978 leftover of #35964 — `$r =& $obj->prop; $r **= n` must write through (ZEND_ASSIGN_OP).
 * php-src: Zend/zend_execute.c zend_binary_assign_op_obj_func / zend_assign_op
 *          Zend/zend_operators.c pow_function
 */
class Same
{
    public $n = 3;
}
$s = new Same();
$s->n **= 2;
echo $s->n, '|';
$r =& $s->n;
$r **= 2;
echo $s->n, '|';

class ParentN
{
    public $n = 3;
}
class ChildN extends ParentN {}
$c = new ChildN();
$c->n **= 2;
$q =& $c->n;
$q **= 2;
echo $c->n, '|';
$x = 3;
$x **= 2;
echo $x, "\n";
