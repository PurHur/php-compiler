<?php

declare(strict_types=1);

/**
 * #35898 leftover of #34649 — `$r =& $obj->prop; $r = N` must write through (Zend MAKE_REF).
 * php-src: Zend/zend_object_handlers.c get_property_ptr_ptr / zend_assign_to_variable_reference
 */
class C
{
    public $x = 1;
}
$c = new C();
$r =& $c->x;
$r = 9;
echo $c->x, "\n";
