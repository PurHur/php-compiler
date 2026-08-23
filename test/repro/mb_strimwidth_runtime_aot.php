<?php

declare(strict_types=1);

/**
 * mb_strimwidth() with runtime start/width under AOT NestedJIT (#34264).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_strimwidth)
 */
$s = 'übercafe';
$i = 0;
$w = 4;
$marker = '..';

echo 'lit=', mb_strimwidth('übercafe', 0, 4, '..'), "\n";
echo 'str_var=', mb_strimwidth($s, 0, 4, '..'), "\n";
echo 'ints_rt=', mb_strimwidth($s, $i, $w, $marker), "\n";
echo 'ascii=', mb_strimwidth('abcdef', 1, 3, '*'), "\n";
