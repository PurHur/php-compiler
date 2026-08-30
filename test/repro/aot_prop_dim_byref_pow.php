<?php
/**
 * #35984 leftover of #35978 — `$r =& $obj->prop[$k]; $r **= n` must write through (ZEND_ASSIGN_DIM_OP).
 * php-src: Zend/zend_execute.c zend_binary_assign_op_obj_dim / ZEND_ASSIGN_DIM_OP
 *          Zend/zend_operators.c pow_function
 */
class C
{
    public $p = ['a' => 3];
}
$o = new C();
$o->p['a'] **= 2;
echo $o->p['a'], '|';
$r =& $o->p['a'];
$r **= 2;
echo $r, '|', $o->p['a'], "\n";
