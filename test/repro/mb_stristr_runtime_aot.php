<?php

declare(strict_types=1);

/**
 * mb_stristr() runtime (non-literal) args under AOT / NestedJIT.
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_stristr)
 */
$h = 'AbAcAd';
$n = 'ac';
$miss = 'zz';
$jp = '日本語本語';

echo 'hit=', var_export(mb_stristr($h, $n), true), "\n";
echo 'miss=', var_export(mb_stristr($h, $miss), true), "\n";
echo 'before=', var_export(mb_stristr($h, $n, true), true), "\n";
echo 'lit=', var_export(mb_stristr('AbAcAd', 'Ac'), true), "\n";
echo 'jp=', var_export(mb_stristr($jp, '本'), true), "\n";
