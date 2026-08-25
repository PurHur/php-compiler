<?php

declare(strict_types=1);

/**
 * AOT: $o->p =& $v must alias both directions (Zend zend_assign_to_variable_reference).
 * After #34648, ASSIGN_REF stores into the property but #34465 strips the alias on $v=….
 *
 * @see php-src Zend/zend_object_handlers.c
 * @see #34649 re-#5370
 */
class C
{
    public $p = 0;
}

$o = new C;
$v = 1;
$o->p = &$v;
$v = 5;
var_export($o->p);
echo "\n";
$o->p = 9;
var_export($v);
echo "\n";
