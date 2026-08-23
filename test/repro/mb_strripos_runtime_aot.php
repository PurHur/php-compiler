<?php

declare(strict_types=1);

/**
 * mb_strripos() runtime (non-literal) args under AOT / NestedJIT.
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_strripos)
 */
$h = 'AbAc';
$n = 'A';
$miss = 'z';
$jp = '日本語本語';

echo 'hit=', var_export(mb_strripos($h, $n), true), "\n";
echo 'miss=', var_export(mb_strripos($h, $miss), true), "\n";
echo 'lit=', var_export(mb_strripos('AbAc', 'a'), true), "\n";
echo 'jp=', var_export(mb_strripos($jp, '語'), true), "\n";
echo 'off=', var_export(mb_strripos($h, $n, 1), true), "\n";
