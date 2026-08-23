<?php

declare(strict_types=1);

/**
 * mb_strstr() runtime (non-literal) args under AOT / NestedJIT.
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_strstr)
 */
$h = 'AbAcAd';
$n = 'Ac';
$miss = 'zz';
$jp = '日本語本語';

echo 'hit=', var_export(mb_strstr($h, $n), true), "\n";
echo 'miss=', var_export(mb_strstr($h, $miss), true), "\n";
echo 'before=', var_export(mb_strstr($h, $n, true), true), "\n";
echo 'lit=', var_export(mb_strstr('AbAcAd', 'Ac'), true), "\n";
echo 'jp=', var_export(mb_strstr($jp, '本'), true), "\n";
