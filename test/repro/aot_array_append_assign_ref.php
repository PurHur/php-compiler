<?php

declare(strict_types=1);

/**
 * AOT: $a[] =& $x / $a['k'] =& $x must alias (Zend zend_assign_to_variable_reference).
 * Leftover of #5349 — thin AOT threw "Reference assignment requires named destination".
 *
 * @see php-src Zend/zend_execute.c zend_assign_to_variable_reference
 * @see #34645
 */
$a = [];
$x = 1;
$a[] = &$x;
$x = 9;
var_export($a);
echo "\n";

$b = [];
$y = 1;
$b['k'] = &$y;
$y = 2;
var_export($b);
echo "\n";
