<?php

declare(strict_types=1);

/**
 * #35987 leftover of #35898 / #33748 — `$r =& $obj->prop; $r ??= N` must write through.
 * php-src: Zend/zend_execute.c ZEND_COALESCE / zend_assign_to_variable_reference
 * php-src: Zend/zend_object_handlers.c zend_std_get_property_ptr_ptr
 */
class C
{
    public $n = null;
}

$o = new C();
$r =& $o->n;
$r ??= 5;
echo "$r|{$o->n}\n";

class D
{
    public $n = 7;
}

$d = new D();
$s =& $d->n;
$s ??= 9;
echo "$s|{$d->n}\n";
