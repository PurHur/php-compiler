<?php

declare(strict_types=1);

/**
 * #34158 — mb_stripos() runtime (non-literal) args under AOT / NestedJIT.
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_stripos)
 */
$h = 'ABC';
$n = 'b';
$miss = 'z';
$jp = '日本語';

echo 'hit=', var_export(mb_stripos($h, $n), true), "\n";
echo 'miss=', var_export(mb_stripos($h, $miss), true), "\n";
echo 'lit=', var_export(mb_stripos('ABC', 'b'), true), "\n";
echo 'jp=', var_export(mb_stripos($jp, '本'), true), "\n";
