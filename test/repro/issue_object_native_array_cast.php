<?php
/**
 * #32468 — Zend convert_to_object(IS_ARRAY) on a packed native list.
 * php-src: Zend/zend_operators.c convert_to_object
 * AOT previously aborted: (object) cast unsupported operand type in JIT: int64[].
 *
 * Avoid var_dump of objects: thin AOT has no Runtime->vm object dumper (#23540).
 */
$o = (object) [1, 2];
echo get_class($o), "\n";
echo $o->{'0'}, "\n";
echo $o->{'1'}, "\n";
