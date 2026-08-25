<?php

declare(strict_types=1);

/**
 * #34778 — nested list by-ref must FETCH_DIM_W the outer container (leftover #34673).
 *
 * @see php-src Zend/zend_execute.c zend_assign_to_variable_reference
 */
$a = [[1, 2]];
[[$x, &$y]] = $a;
echo "read: {$x}|{$y}\n";
$y = 99;
echo "write-through: {$a[0][1]}\n";

$b = [[1, 2], [3, 4]];
[[$p, &$q], [$r, $s]] = $b;
$q = 77;
echo "multi-nest: {$b[0][1]}|{$r}|{$s}\n";
