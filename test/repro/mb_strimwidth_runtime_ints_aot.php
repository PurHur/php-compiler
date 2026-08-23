<?php

declare(strict_types=1);

/**
 * mb_strimwidth() runtime start/width under AOT / NestedJIT (#34264 leftover of #3495).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_strimwidth)
 */
$s = 'übercafe';
$i = 0;
$w = 4;

echo 'lit=', mb_strimwidth('übercafe', 0, 4, '..'), "\n";
echo 'str_var=', mb_strimwidth($s, 0, 4, '..'), "\n";
echo 'ints_var=', mb_strimwidth($s, $i, $w, '..'), "\n";
