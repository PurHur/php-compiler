<?php

declare(strict_types=1);

/**
 * #35964 leftover of #35898 — `$r =& $obj->prop; $r .= s` must write through (ZEND_ASSIGN_OP).
 * php-src: Zend/zend_execute.c zend_binary_assign_op_obj_func / zend_assign_op
 *          Zend/zend_object_handlers.c zend_std_get_property_ptr_ptr
 */
class Same
{
    public $p = 'a';
}
$s = new Same();
$r =& $s->p;
$r .= 'x';
echo $s->p, '|';

class ParentP
{
    public $p = 'a';
}
class ChildP extends ParentP {}
$c = new ChildP();
$q =& $c->p;
$q .= 'x';
echo $c->p, "\n";
