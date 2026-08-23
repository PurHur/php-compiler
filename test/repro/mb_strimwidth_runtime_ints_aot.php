<?php

declare(strict_types=1);

/**
 * mb_strimwidth() runtime start/width under AOT / NestedJIT (#34264 / #34269).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_strimwidth)
 *
 * Must match Zend exactly — #34266 stopped SIGSEGV but NestedJIT still returned only "..".
 */
$s = 'übercafe';
$i = 0;
$w = 4;

echo 'lit=', mb_strimwidth('übercafe', 0, 4, '..'), "\n";
echo 'str_var=', mb_strimwidth($s, 0, 4, '..'), "\n";
echo 'ints_var=', mb_strimwidth($s, $i, $w, '..'), "\n";
$f = 0;
$w3 = 3;
echo 'ü_w3=', mb_strimwidth('über', $f, $w3, '..'), "\n";
$f = 1;
echo 'from1=', mb_strimwidth('über', $f, $w3, '..'), "\n";
