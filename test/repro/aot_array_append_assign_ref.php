<?php

declare(strict_types=1);

/**
 * AOT: $a[] =& $x / $a['k'] =& $x must alias (Zend ZEND_ASSIGN_REF into FETCH_DIM_W).
 *
 * @see php-src Zend/zend_execute.c zend_assign_to_variable_reference
 * @see #34645 re-#5349
 */
$a = [];
$x = 1;
$a[] =& $x;
$x = 9;
echo $a[0], "\n";

$b = [];
$y = 1;
$b['k'] =& $y;
$y = 7;
echo $b['k'], "\n";
