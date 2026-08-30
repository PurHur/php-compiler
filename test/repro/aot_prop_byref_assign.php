<?php

declare(strict_types=1);

/**
 * AOT: `$r = &$obj->prop; $r = N` must write through like Zend (#35898).
 *
 * Peer of `$o->p = &$v` (#34649) and `$r = &$a[0]` (#34645 / #32669).
 *
 * @see php-src Zend/zend_object_handlers.c zend_std_get_property_ptr_ptr
 * @see php-src Zend/zend_execute.c zend_assign_to_variable_reference
 */
class C
{
    public $x = 1;
}

$c = new C();
$r = &$c->x;
$r = 9;
echo $c->x, "\n";
