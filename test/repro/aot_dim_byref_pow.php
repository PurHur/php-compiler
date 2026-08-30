<?php
/**
 * #35984 leftover of #35978 — `$r =& $a[$k]` / `$r =& $obj->prop[$k]; $r **= n` must write through.
 * php-src: Zend/zend_execute.c ZEND_ASSIGN_DIM_OP / zend_binary_assign_op_obj_dim
 *          Zend/zend_operators.c pow_function
 */
$a = ['a' => 3];
$r =& $a['a'];
$r **= 2;
echo $r, '|', $a['a'], '|';

class C
{
    public $p = ['a' => 3];
}
$o = new C();
$s =& $o->p['a'];
$s **= 2;
echo $s, '|', $o->p['a'], "\n";
