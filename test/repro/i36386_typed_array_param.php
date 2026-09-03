<?php

declare(strict_types=1);

/**
 * Typed `array` formals must keep the caller's HT alive (#36386).
 *
 * php-src: Zend/zend_execute_API.c zend_get_parameters_array_ex (ADDREF).
 */

function count_a(array $a): int
{
    return count($a);
}

function first(array $a): int
{
    return $a[0];
}

$a = [5, 6];
echo count_a($a), ':', $a[0], ':', first($a), "\n";
echo count_a([7, 8, 9]), "\n";
