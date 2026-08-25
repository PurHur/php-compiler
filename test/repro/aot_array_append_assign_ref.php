<?php

declare(strict_types=1);

/**
 * AOT: $a[] =& $x / $a['k'] =& $x must alias (Zend ZEND_ASSIGN_REF into FETCH_DIM_W).
 * Double append of the same variable must leave both slots as aliases (#34685).
 * Packed `$a[0]=&$x` on an empty array must materialise the slot (#34689).
 *
 * @see php-src Zend/zend_execute.c zend_assign_to_variable_reference
 * @see #34645 re-#5349
 * @see #34685
 * @see #34689
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

$c = [];
$z = 1;
$c[] =& $z;
$c[] =& $z;
$z = 9;
echo $c[0], ",", $c[1], "\n";

$d = [];
$w = 1;
$d[0] =& $w;
$w = 9;
echo $d[0], "\n";
