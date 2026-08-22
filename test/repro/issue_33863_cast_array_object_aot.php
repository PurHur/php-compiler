<?php

declare(strict_types=1);

// AOT (array) object path — leftover SIGSEGV after #33869 (#33863 follow-up).
// php-src: Zend/zend_operators.c convert_to_array; ext/spl/spl_array.c ARRAY_CAST

$o = new stdClass();
$o->x = 9;
echo ((array) $o)['x'], "\n";

$ao = new ArrayObject([3, 4]);
echo implode(',', (array) $ao), "\n";
