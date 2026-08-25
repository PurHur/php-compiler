<?php

declare(strict_types=1);

/**
 * AOT: $a[0] =& $x on an empty (or holey) packed array must materialise the slot (#34689).
 * Append / string-key paths already worked (#34645 / #34685); packed index did not grow `used`.
 *
 * @see php-src Zend/zend_execute.c zend_assign_to_variable_reference
 * @see #34645 #34685
 */
$a = [];
$x = 1;
$a[0] =& $x;
$x = 9;
echo $a[0], "\n";

$b = [1, 2];
$y = 3;
$b[1] =& $y;
$y = 7;
echo $b[0], ",", $b[1], "\n";

$c = [];
$z = 1;
$c[0] =& $z;
$c[2] =& $z;
$z = 5;
echo $c[0], ",", (isset($c[1]) ? '1' : '_'), ",", $c[2], "\n";
