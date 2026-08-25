<?php

declare(strict_types=1);

/**
 * AOT: [&$x, &$y] = $a must bind each slot to the array element (#34673).
 *
 * @see php-src Zend/zend_execute.c zend_assign_to_variable_reference
 */
$a = [1, 2];
[$x, $y] = $a;
echo "by-value: {$x}|{$y}\n";

$a = [1, 2];
[&$x, &$y] = $a;
echo "read: {$x}|{$y}\n";
$x = 9;
echo "write-through: {$a[0]}|{$y}\n";

$a = [1, 2];
[&$x] = $a;
$x = 9;
echo "single-ref: {$a[0]}\n";
