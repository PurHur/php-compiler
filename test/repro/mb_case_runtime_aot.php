<?php

declare(strict_types=1);

/**
 * mb_strtoupper() / mb_strtolower() runtime (non-literal) args under AOT / NestedJIT.
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_strtoupper), PHP_FUNCTION(mb_strtolower)
 */
$s = 'AbAcAd';
$jp = '日本語';
$sharp = 'straße';

echo 'up_var=', mb_strtoupper($s), "\n";
echo 'up_lit=', mb_strtoupper('hello'), "\n";
echo 'up_jp=', mb_strtoupper($jp), "\n";
echo 'up_sharp=', mb_strtoupper($sharp), "\n";
echo 'low_var=', mb_strtolower($s), "\n";
echo 'low_lit=', mb_strtolower('HELLO'), "\n";
echo 'low_jp=', mb_strtolower($jp), "\n";
