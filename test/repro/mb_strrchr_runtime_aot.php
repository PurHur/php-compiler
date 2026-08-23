<?php

declare(strict_types=1);

/**
 * mb_strrchr()/mb_strrichr() runtime (non-literal) args under AOT / NestedJIT.
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_strrchr), mb_strrichr
 */
$h = 'AbAcAd';
$n = 'Ac';
$miss = 'zz';
$jp = '日本語本語';

echo 'rchr_hit=', var_export(mb_strrchr($h, $n), true), "\n";
echo 'rchr_miss=', var_export(mb_strrchr($h, $miss), true), "\n";
echo 'rchr_before=', var_export(mb_strrchr($h, $n, true), true), "\n";
echo 'rchr_lit=', var_export(mb_strrchr('AbAcAd', 'Ac'), true), "\n";
echo 'rchr_jp=', var_export(mb_strrchr($jp, '本'), true), "\n";

echo 'richr_hit=', var_export(mb_strrichr($h, 'ac'), true), "\n";
echo 'richr_miss=', var_export(mb_strrichr($h, $miss), true), "\n";
echo 'richr_before=', var_export(mb_strrichr($h, 'ac', true), true), "\n";
echo 'richr_lit=', var_export(mb_strrichr('AbAcAd', 'Ac'), true), "\n";
echo 'richr_jp=', var_export(mb_strrichr($jp, '本'), true), "\n";
