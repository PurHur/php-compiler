<?php

declare(strict_types=1);

/**
 * AOT: $o->p =& $v must alias both directions (Zend ZEND_ASSIGN_REF into FETCH_OBJ_W).
 *
 * @see php-src Zend/zend_object_handlers.c zend_std_get_property_ptr_ptr
 * @see php-src Zend/zend_execute.c zend_assign_to_variable_reference
 * @see #34649 re-#5370 / peer #34645
 */
class C
{
    public $p = 0;
}

$o = new C();
$v = 1;
$o->p =& $v;
$v = 5;
echo $o->p, "\n";
$o->p = 9;
echo $v, "\n";
