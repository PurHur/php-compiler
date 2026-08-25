<?php

declare(strict_types=1);

/**
 * AOT: boxed bool ⊕ int must coerce true→1 before long arithmetic (#34678).
 *
 * @see php-src Zend/zend_operators.c convert_to_long
 */
function box($x)
{
    return $x;
}

var_dump(box(true) + box(2));
var_dump(box(true) * box(3));
var_dump(box(true) - box(1));
